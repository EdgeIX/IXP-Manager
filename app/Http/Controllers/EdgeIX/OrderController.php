<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use IXP\Exceptions\EdgeIX\InsufficientPortStockException;

use IXP\Http\Controllers\Controller;

use IXP\Models\PortOrder;
use IXP\Models\User;

use IXP\Services\EdgeIX\PortOrderService;
use IXP\Services\EdgeIX\PortStockService;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * EdgeIX customer order flows (ordering Phase 3).
 *
 * The /order routes sit behind the 'msa' middleware (EnsureMsaSigned) —
 * no executed MSA, no ordering. Custadmin (or superuser) only: regular
 * custusers don't have authority to order.
 *
 * index  → New Port wizard (stock-driven: only locations/types/quantities
 *          that are actually sellable are offered) + the customer's
 *          existing orders. Add-to-LAG / Upgrade are later phases.
 * store  → validate + PortOrderService::place() (locked reservation,
 *          auto-approve policy, admin notify).
 * view   → order status page (incl. the MAC-missing nag).
 *
 * See docs/ordering.md.
 */
class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware( 'auth' );
        $this->middleware( function ( $request, $next ) {
            if( $request->user()->privs() < User::AUTH_CUSTADMIN ) {
                AlertContainer::push( 'Ordering requires customer administrator access.', Alert::DANGER );
                return redirect( '' );
            }
            return $next( $request );
        } );
    }

    public function index( Request $r, PortStockService $stock ): View
    {
        $cust = $r->user()->customer;

        $maintenance = (bool)config( 'ordering.maintenance' );

        return view( 'order.index', [
            'orderCust'         => $cust,
            'orderMaintenance'  => $maintenance,
            'orderMaintMessage' => (string)config( 'ordering.maintenance_message' ),
            'orderAvailability' => $maintenance ? [] : $stock->availability( $stock->rows() ),
            'orderMyOrders'     => PortOrder::where( 'custid', $cust->id )
                ->with( [ 'location', 'portType' ] )
                ->orderByDesc( 'id' )->limit( 25 )->get(),
        ] );
    }

    public function store( Request $r, PortOrderService $orders ): RedirectResponse
    {
        // Server-side guard, not just hidden UI — maintenance means no
        // placements, full stop (ORDER_MAINTENANCE).
        if( config( 'ordering.maintenance' ) ) {
            AlertContainer::push( e( config( 'ordering.maintenance_message' ) ), Alert::WARNING );
            return redirect()->route( 'order@index' );
        }

        $cust = $r->user()->customer;

        $data = $r->validate( [
            'locationid'       => 'required|integer|exists:location,id',
            'port_type_id'     => 'required|integer|exists:port_type,id',
            'quantity'         => 'required|integer|min:1|max:8',
            'tagged'           => 'required|boolean',
            'vlan_tag'         => 'required_if:tagged,1|nullable|integer|min:1|max:4094',
            'macs'             => 'nullable|array|max:2',
            'macs.*'           => [ 'nullable', 'regex:/^([0-9a-f]{2}[:\.-]?){5}[0-9a-f]{2}$/i' ],
            'delivery_contact' => 'required|string|max:255',
            'po_number'        => 'nullable|string|max:64',
            'preferred_golive' => 'nullable|date|after_or_equal:today',
        ] );

        // Drop empty MAC inputs; null when none provided ("provide later").
        $data['macs'] = array_values( array_filter( $data['macs'] ?? [] ) ) ?: null;

        try {
            $order = $orders->place( $cust, $r->user(), $data );
        } catch( InsufficientPortStockException $e ) {
            // Only thrown for excluded (manual-only) locations now — no
            // stock is accepted as a backorder instead.
            AlertContainer::push(
                "Online ordering isn't available at that site — please email "
                . "<a href=\"mailto:sales@edgeix.net\">sales@edgeix.net</a> and we'll arrange it directly.",
                Alert::WARNING
            );
            return redirect()->route( 'order@index' )->withInput();
        }

        if( $order->state === PortOrder::STATE_BACKORDER ) {
            AlertContainer::push( "Order #{$order->id} accepted. There's no pre-provisioned capacity for this right now — our team will build it and be in touch with delivery timing.", Alert::SUCCESS );
        } else {
            AlertContainer::push( "Order #{$order->id} placed.", Alert::SUCCESS );
        }
        return redirect()->route( 'order@view', [ 'order' => $order->id ] );
    }

    public function view( Request $r, PortOrder $order ): View|RedirectResponse
    {
        if( !$r->user()->isSuperUser() && $order->custid !== $r->user()->custid ) {
            AlertContainer::push( 'Order not found.', Alert::DANGER );
            return redirect()->route( 'order@index' );
        }

        return view( 'order.view', [
            'orderOrder' => $order->load( [ 'location', 'customerLocation', 'portType', 'portOrderPorts.switchPort.switcher' ] ),
        ] );
    }
}
