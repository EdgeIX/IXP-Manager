<?php

namespace IXP\Services\EdgeIX;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use IXP\Exceptions\EdgeIX\InsufficientPortStockException;

use IXP\Models\Customer;
use IXP\Models\PatchPanelPort;
use IXP\Models\PortOrder;
use IXP\Models\PortOrderPort;
use IXP\Models\PortType;
use IXP\Models\SwitchPort;
use IXP\Models\User;

/**
 * EdgeIX ordering Phase 3: order placement, reservation and lifecycle.
 *
 * place() runs as ONE DB transaction with FOR UPDATE row locks over the
 * sellable candidates, so two concurrent orders can never reserve the
 * same last port. The reservation is the port_order_port rows — stock
 * queries exclude switch ports held by open orders.
 *
 * Approval policy (config ordering.auto_approve / ORDER_AUTO_APPROVE):
 *   all      → every order auto-approves at placement (the zero-touch
 *              default per docs/ordering.md "Approval model").
 *   existing → auto-approve only customers with existing services;
 *              first-ever ports wait for an admin.
 *   none     → every order waits for an admin.
 *
 * Every placement emails ORDER_NOTIFY_EMAIL regardless — admin awareness
 * is not approval, and it's the manual-billing trigger.
 *
 * Downstream automation (auto-provisioning per the recipe, LOA issue) is
 * driven from approve() as later phases land; for now approval is the
 * state checkpoint they will hang off.
 */
class PortOrderService
{
    /**
     * Place an order: validate stock, reserve port(s), notify, and apply
     * the auto-approval policy.
     *
     * $data: locationid, port_type_id, quantity, tagged, vlan_tag, macs,
     * delivery_contact, po_number, preferred_golive.
     *
     * @throws InsufficientPortStockException
     */
    public function place( Customer $cust, ?User $user, array $data ): PortOrder
    {
        $type = PortType::findOrFail( $data['port_type_id'] );
        $qty  = max( 1, (int)( $data['quantity'] ?? 1 ) );

        // Served-via alias (passive/campus site): remember what the
        // customer selected, resolve stock/reservation/LOA to the demarc.
        $selectedLoc = \IXP\Models\Location::findOrFail( (int)$data['locationid'] );
        $customerLocationId = null;
        if( $selectedLoc->served_via_locationid ) {
            $customerLocationId  = $selectedLoc->id;
            $data['locationid']  = $selectedLoc->served_via_locationid;
        }

        // Manual-only sites never take self-serve orders, whatever the UI
        // was coaxed into submitting — selected site and demarc both.
        $excluded = config( 'ordering.excluded_locations', [] );
        if( in_array( (int)$selectedLoc->id, $excluded, true )
            || in_array( (int)$data['locationid'], $excluded, true ) ) {
            throw new InsufficientPortStockException( 'Location ' . (int)$selectedLoc->id . ' is excluded from self-serve ordering (ORDER_EXCLUDED_LOCATIONS)' );
        }

        $fields = [
            'custid'           => $cust->id,
            'user_id'          => $user?->id,
            'kind'             => PortOrder::KIND_NEW_PORT,
            'locationid'       => (int)$data['locationid'],
            'customer_locationid' => $customerLocationId,
            'port_type_id'     => $type->id,
            'quantity'         => $qty,
            'tagged'           => (bool)( $data['tagged'] ?? false ),
            'vlan_tag'         => $data['vlan_tag'] ?? null,
            'macs'             => $data['macs'] ?? null,
            'delivery_contact' => $data['delivery_contact'] ?? null,
            'po_number'        => $data['po_number'] ?? null,
            'preferred_golive' => $data['preferred_golive'] ?? null,
        ];

        try {
            $order = DB::transaction( function () use ( $cust, $data, $type, $qty, $fields ) {

                $ports = $this->reserveCandidates( $cust, (int)$data['locationid'], $type, $qty );

                $order = PortOrder::create( $fields + [
                    'state'          => PortOrder::STATE_SUBMITTED,
                    'reserved_until' => now()->addDays( (int)config( 'ordering.hold_days', 14 ) ),
                ] );

                foreach( $ports as $sp ) {
                    PortOrderPort::create( [
                        'port_order_id'       => $order->id,
                        'switchportid'        => $sp->id,
                        'patch_panel_port_id' => $sp->patchPanelPort?->id,
                    ] );
                }

                return $order;
            } );
        } catch( InsufficientPortStockException $e ) {
            // NEVER refuse an order: no stock → accept as BACKORDER (no
            // reservation, no hold clock). Admins arrange cabling / a new
            // switch, then "Reserve ports" from the queue when capacity
            // exists.
            $order = PortOrder::create( $fields + [
                'state'          => PortOrder::STATE_BACKORDER,
                'reserved_until' => null,
            ] );

            Log::warning( "[PortOrder] #{$order->id} BACKORDER — no stock ({$e->getMessage()})" );
        }

        Log::info( sprintf( "[PortOrder] #%d placed (%s): [%d|%s] %dx %s at location %d by %s",
            $order->id, $order->state, $cust->id, $cust->name, $qty, $type->name, $order->locationid, $user?->username ?? 'n/a' ) );

        $this->notifyAdmins( $order );

        if( $order->state === PortOrder::STATE_SUBMITTED && $this->policyAutoApproves( $cust ) ) {
            $this->approve( $order, null );
        }

        return $order;
    }

    /**
     * Admin action on a BACKORDER once capacity has been built: reserve
     * the port(s) and approve in one go (the admin's click IS the
     * approval). Throws InsufficientPortStockException when there is
     * still nothing to reserve.
     */
    public function reserveBackorder( PortOrder $order, ?User $admin ): PortOrder
    {
        if( $order->state !== PortOrder::STATE_BACKORDER ) {
            return $order;
        }

        $order->loadMissing( [ 'customer', 'portType' ] );

        DB::transaction( function () use ( $order ) {
            $ports = $this->reserveCandidates( $order->customer, (int)$order->locationid, $order->portType, (int)$order->quantity );

            foreach( $ports as $sp ) {
                PortOrderPort::create( [
                    'port_order_id'       => $order->id,
                    'switchportid'        => $sp->id,
                    'patch_panel_port_id' => $sp->patchPanelPort?->id,
                ] );
            }

            $order->state = PortOrder::STATE_SUBMITTED;
            $order->save();
        } );

        Log::info( "[PortOrder] #{$order->id} backorder reserved by " . ( $admin?->username ?? 'system' ) );

        return $this->approve( $order, $admin );
    }

    /**
     * Approve: the single gate. Everything downstream (auto-provisioning,
     * LOA issue + email) runs from here as those phases land.
     */
    public function approve( PortOrder $order, ?User $admin ): PortOrder
    {
        if( $order->state !== PortOrder::STATE_SUBMITTED ) {
            return $order;
        }

        $order->state          = PortOrder::STATE_APPROVED;
        $order->approved_at    = now();
        $order->approved_by    = $admin?->id;      // null = auto-approved by policy
        $order->reserved_until = null;             // approval ends the hold clock
        $order->save();

        Log::info( "[PortOrder] #{$order->id} approved by " . ( $admin?->username ?? 'policy (auto)' ) );

        // Later phases hook in here: auto-provision per the recipe in
        // docs/ordering.md, then LOA generation + email.

        return $order;
    }

    public function cancel( PortOrder $order, string $reason, ?User $by = null ): PortOrder
    {
        if( !$order->isOpen() ) {
            return $order;
        }

        $order->state         = PortOrder::STATE_CANCELLED;
        $order->cancelled_at  = now();
        $order->cancel_reason = mb_substr( $reason, 0, 255 );
        $order->save();

        Log::info( "[PortOrder] #{$order->id} cancelled by " . ( $by?->username ?? 'system' ) . ": {$reason}" );

        return $order;
    }

    /**
     * Expire submitted orders whose approval hold has run out, freeing
     * their reserved ports. Returns the number expired.
     */
    public function expireHolds(): int
    {
        $expired = 0;

        PortOrder::where( 'state', PortOrder::STATE_SUBMITTED )
            ->whereNotNull( 'reserved_until' )
            ->where( 'reserved_until', '<', now() )
            ->each( function ( PortOrder $order ) use ( &$expired ) {
                $order->state        = PortOrder::STATE_EXPIRED;
                $order->cancelled_at = now();
                $order->save();
                $expired++;
                Log::warning( "[PortOrder] #{$order->id} expired — approval hold ran out, port(s) released" );
            } );

        return $expired;
    }

    /**
     * Lock and return $qty sellable switch ports at the location, all on
     * ONE switch (LAG members must share a switch).
     *
     * Sellable = active peering port on an active switch at the location,
     * effective port type = requested (override wins), no physical
     * interface, panel port PREWIRED, not held by an open order.
     *
     * SWITCH DIVERSITY: switches where this customer already has ports
     * (in service or reserved by another open order) are considered LAST —
     * so a customer ordering a second service at the same DC for router
     * redundancy automatically lands on a different switch when stock
     * allows, without being asked. Falls back to a shared switch when
     * that's all that's left.
     *
     * @return \Illuminate\Support\Collection<int, SwitchPort>
     * @throws InsufficientPortStockException
     */
    private function reserveCandidates( Customer $cust, int $locationId, PortType $type, int $qty )
    {
        // Location is matched on the PANEL side (the demarc the customer
        // x-connects to / the LOA names) — same as PortStockService's
        // attribution. For co-located pairs this equals the switch's site;
        // for long-lined pairs it's the remote passive site.
        $candidates = SwitchPort::query()
            ->select( 'switchport.*' )
            ->join( 'switch', 'switch.id', 'switchport.switchid' )
            ->join( 'patch_panel_port', 'patch_panel_port.switch_port_id', 'switchport.id' )
            ->join( 'patch_panel', 'patch_panel.id', 'patch_panel_port.patch_panel_id' )
            ->join( 'cabinet', 'cabinet.id', 'patch_panel.cabinet_id' )
            ->leftJoin( 'physicalinterface', 'physicalinterface.switchportid', 'switchport.id' )
            ->where( 'switch.active', true )
            ->where( 'cabinet.locationid', $locationId )
            ->where( 'switchport.active', true )
            ->where( 'switchport.type', SwitchPort::TYPE_PEERING )
            ->where( 'patch_panel_port.state', PatchPanelPort::STATE_PREWIRED )
            ->whereNull( 'physicalinterface.id' )
            ->where( function ( $q ) use ( $type ) {
                $q->where( 'switchport.port_type_override_id', $type->id )
                  ->orWhere( function ( $q2 ) use ( $type ) {
                      $q2->whereNull( 'switchport.port_type_override_id' )
                         ->where( 'switchport.port_type_id', $type->id );
                  } );
            } )
            ->whereNotIn( 'switchport.id', function ( $q ) {
                $q->select( 'port_order_port.switchportid' )
                  ->from( 'port_order_port' )
                  ->join( 'port_order', 'port_order.id', 'port_order_port.port_order_id' )
                  ->whereIn( 'port_order.state', PortOrder::OPEN_STATES );
            } )
            ->orderBy( 'switchport.switchid' )
            ->orderBy( 'switchport.id' )
            ->lockForUpdate()
            ->get();

        // Switches where the customer already has a presence (active ports,
        // or ports reserved by another of their open orders).
        $presentOn = SwitchPort::query()
                ->join( 'physicalinterface', 'physicalinterface.switchportid', 'switchport.id' )
                ->join( 'virtualinterface', 'virtualinterface.id', 'physicalinterface.virtualinterfaceid' )
                ->where( 'virtualinterface.custid', $cust->id )
                ->pluck( 'switchport.switchid' )
            ->merge(
                SwitchPort::query()
                    ->join( 'port_order_port', 'port_order_port.switchportid', 'switchport.id' )
                    ->join( 'port_order', 'port_order.id', 'port_order_port.port_order_id' )
                    ->where( 'port_order.custid', $cust->id )
                    ->whereIn( 'port_order.state', PortOrder::OPEN_STATES )
                    ->pluck( 'switchport.switchid' )
            )->unique()->flip();

        $groups = $candidates->groupBy( 'switchid' );

        foreach( [ false, true ] as $allowSharedSwitch ) {
            foreach( $groups as $switchId => $portsOnSwitch ) {
                if( isset( $presentOn[ $switchId ] ) !== $allowSharedSwitch ) {
                    continue;
                }
                if( $portsOnSwitch->count() >= $qty ) {
                    return $portsOnSwitch->take( $qty )->values();
                }
            }
        }

        throw new InsufficientPortStockException( sprintf(
            'No stock: %d x %s at location %d (largest same-switch availability: %d)',
            $qty, $type->name, $locationId,
            (int)$candidates->groupBy( 'switchid' )->map->count()->max()
        ) );
    }

    private function policyAutoApproves( Customer $cust ): bool
    {
        return match ( config( 'ordering.auto_approve', 'all' ) ) {
            'all'      => true,
            'existing' => $cust->virtualInterfaces()->exists(),
            default    => false,
        };
    }

    private function notifyAdmins( PortOrder $order ): void
    {
        $to = config( 'ordering.notify_email' );
        if( !$to ) {
            return;
        }

        $order->loadMissing( [ 'customer', 'location', 'customerLocation', 'portType', 'portOrderPorts.switchPort.switcher' ] );

        $lines = [
            'New port order #' . $order->id
                . ( $order->state === PortOrder::STATE_BACKORDER ? '  ** BACKORDER — ACTION NEEDED **' : '' ),
            str_repeat( '=', 40 ),
            'Customer:  [' . $order->custid . '] ' . $order->customer?->name,
            'Order:     ' . $order->quantity . ' x ' . $order->portType?->name
                . ( $order->quantity > 1 ? ' (LACP LAG)' : '' )
                . ' at ' . ( $order->location?->name ?? ( 'location ' . $order->locationid ) )
                . ( $order->customer_locationid
                    ? ' (customer at ' . ( $order->customerLocation?->name ?? $order->customer_locationid ) . ' — served-via site, demarc/LOA at ' . $order->location?->name . ')'
                    : '' ),
            'Tagged:    ' . ( $order->tagged ? ( 'yes, VLAN ' . $order->vlan_tag ) : 'no' ),
            'MAC(s):    ' . ( $order->macMissing() ? 'not provided yet (provisioning nag active)' : implode( ', ', $order->macs ) ),
            'Reserved:  ' . ( $order->state === PortOrder::STATE_BACKORDER
                ? 'NONE — no sellable stock at this site. Arrange cabling / capacity, then use "Reserve ports" on the order.'
                : $order->portOrderPorts->map( fn( $p ) =>
                    ( $p->switchPort?->switcher?->name ?? '?' ) . ':' . ( $p->switchPort?->ifName ?? '?' ) )->implode( ', ' ) ),
            '',
            'Billing is manual — action per trial/contract terms.',
            'Admin: ' . url( '/admin/port-order/view/' . $order->id ),
        ];

        try {
            Mail::raw( implode( "\n", $lines ), function ( $m ) use ( $to, $order ) {
                $m->to( $to )->subject( '[IXP-Manager] New port order #' . $order->id . ' — ' . $order->customer?->name );
            } );
        } catch( \Throwable $e ) {
            Log::error( "[PortOrder] #{$order->id} notify email failed: " . $e->getMessage() );
        }
    }
}
