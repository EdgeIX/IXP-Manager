<?php

namespace IXP\Services\EdgeIX;

use Illuminate\Support\Collection;

use Illuminate\Support\Facades\DB;

use IXP\Models\Location;
use IXP\Models\PatchPanelPort;
use IXP\Models\PortOrder;
use IXP\Models\PortOrderPort;
use IXP\Models\PortType;
use IXP\Models\SwitchPort;

/**
 * EdgeIX ordering Phase 3: sellable port stock computation.
 *
 * One definition of "sellable", shared by the admin Port Stock page, the
 * low-stock alerter and (later) the order form's availability logic:
 * active peering switch port + effective port type + no physical interface
 * + patch panel port in PREWIRED state. See docs/ordering.md.
 */
class PortStockService
{
    /**
     * One row object per active peering switch port on an active switch.
     *
     * @return Collection<int, object{sp: SwitchPort, location: mixed, type: mixed, free: bool, prewired: bool, ppp: mixed, sellable: bool, needsPrewireFlag: bool, unmatchedOptic: bool}>
     */
    /**
     * Offering map: [ port_type_id => [ locationid => true ] ]. A type
     * absent from the map is unrestricted (offered wherever detected).
     */
    public function offeredMap(): array
    {
        $map = [];
        foreach( DB::table( 'port_type_location' )->get() as $row ) {
            $map[ (int)$row->port_type_id ][ (int)$row->locationid ] = true;
        }
        return $map;
    }

    /**
     * Is $type offered at $locId? Unrestricted types are offered anywhere.
     */
    private function offeredAt( array $map, int $typeId, ?int $locId ): bool
    {
        return !isset( $map[ $typeId ] ) || ( $locId && isset( $map[ $typeId ][ $locId ] ) );
    }

    public function rows(): Collection
    {
        $offered = $this->offeredMap();

        // Switch ports held by an OPEN order are reserved — not sellable.
        $reserved = PortOrderPort::whereHas( 'portOrder', fn( $q ) =>
                $q->whereIn( 'state', PortOrder::OPEN_STATES ) )
            ->pluck( 'switchportid' )->flip();

        return SwitchPort::with( [ 'switcher.cabinet.location', 'patchPanelPort.patchPanel', 'physicalInterface', 'portType', 'portTypeOverride' ] )
            ->where( 'active', true )
            ->where( 'type', SwitchPort::TYPE_PEERING )
            ->whereHas( 'switcher', fn( $q ) => $q->where( 'active', true ) )
            ->get()
            ->map( function( SwitchPort $sp ) use ( $reserved, $offered ) {
                $type = $sp->effectivePortType();
                $ppp  = $sp->patchPanelPort;
                $loc  = $sp->switcher?->cabinet?->location;
                $free = !$sp->physicalInterface;
                $prewired   = $ppp && (int)$ppp->state === PatchPanelPort::STATE_PREWIRED;
                $isReserved = isset( $reserved[ $sp->id ] );
                // Offering map: a stray optic at a site that doesn't offer
                // the type never becomes stock or prewire-hygiene noise.
                $offeredHere = $type && $this->offeredAt( $offered, $type->id, $loc?->id );

                return (object)[
                    'sp'        => $sp,
                    'location'  => $loc,
                    'type'      => $type,
                    'free'      => $free,
                    'prewired'  => $prewired,
                    'reserved'  => $isReserved,
                    'offered'   => $offeredHere,
                    'ppp'       => $ppp,
                    // sellable = what the order form may offer
                    'sellable'  => $free && $type && $type->active && $prewired && !$isReserved && $offeredHere,
                    // hygiene: free port with a sellable type but panel side not prewired
                    'needsPrewireFlag' => $free && $type && $type->active && !$prewired && !$isReserved && $offeredHere,
                    // hygiene: optic detected but no catalogue match, port free
                    'unmatchedOptic'   => $free && !$type && $sp->detected_xcvr,
                ];
            } );
    }

    /**
     * Sellable counts: [ location id => [ port type id => count ] ].
     */
    public function sellableMatrix( Collection $rows ): array
    {
        $matrix = [];
        foreach( $rows->where( 'sellable', true ) as $r ) {
            $locId = $r->location?->id ?? 0;
            $matrix[ $locId ][ $r->type->id ] = ( $matrix[ $locId ][ $r->type->id ] ?? 0 ) + 1;
        }
        return $matrix;
    }

    /**
     * Availability tree for the order form: only what a customer may
     * order. [ locationId => [ 'name', 'types' => [ typeId => [ 'name',
     * 'speed', 'speedLabel', 'maxSameSwitch' (largest same-switch pool —
     * caps the LAG quantity selector), 'total' ] ] ] ].
     */
    public function availability( Collection $rows ): array
    {
        $out = [];

        foreach( $rows->where( 'sellable', true ) as $r ) {
            if( !$r->location ) {
                continue;
            }
            $out[ $r->location->id ]['name'] ??= $r->location->name;
            $out[ $r->location->id ]['types'][ $r->type->id ]['ports'][] = $r->sp->switchid;
        }

        foreach( $out as $locId => &$loc ) {
            foreach( $loc['types'] as $typeId => &$t ) {
                $type = $rows->first( fn( $r ) => $r->type?->id === $typeId )->type;
                $bySwitch = collect( $t['ports'] )->countBy();
                $t = [
                    'name'          => $type->name,
                    'speed'         => (int)$type->speed,
                    'speedLabel'    => $type->speedLabel(),
                    'maxSameSwitch' => (int)$bySwitch->max(),
                    'total'         => count( $t['ports'] ),
                ];
            }
            unset( $t );
        }
        unset( $loc );

        return $out;
    }

    /**
     * Low-stock shortfalls: one entry per (location × type) below its
     * threshold. The threshold is one number per type, evaluated PER DC.
     *
     * Which (location × type) pairs are checked:
     *  - Unrestricted type (no offering map): every location that DEPLOYS
     *    it (any port of that effective type exists there, in service or
     *    not) — sites that never stock it stay silent.
     *  - Restricted type (offering map set): exactly its offered sites —
     *    INCLUDING sites with zero ports of the type (capable-but-empty is
     *    a shortfall), and never anywhere else (stray optics don't alert).
     *
     * @return array<int, object{location: mixed, type: mixed, sellable: int, threshold: int, total: int}>
     */
    public function shortfalls( Collection $rows ): array
    {
        $offered = $this->offeredMap();
        $out     = [];
        $seen    = [];

        $byLoc = $rows->filter( fn( $r ) => $r->type && $r->type->active && $r->type->low_stock_threshold !== null )
            ->groupBy( fn( $r ) => ( $r->location?->id ?? 0 ) . ':' . $r->type->id );

        foreach( $byLoc as $key => $group ) {
            $first = $group->first();
            $type  = $first->type;

            // Restricted type at a non-offering site: stray optics, no alert.
            if( !$this->offeredAt( $offered, $type->id, $first->location?->id ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $sellable = $group->where( 'sellable', true )->count();

            if( $sellable < (int)$type->low_stock_threshold ) {
                $out[] = (object)[
                    'location'  => $first->location,
                    'type'      => $type,
                    'sellable'  => $sellable,
                    'threshold' => (int)$type->low_stock_threshold,
                    'total'     => $group->count(),
                ];
            }
        }

        // Capable-but-empty: offered sites with NO ports of the type at all.
        $restrictedTypes = PortType::active()->whereNotNull( 'low_stock_threshold' )
            ->whereIn( 'id', array_keys( $offered ) )->get();

        $locIds = [];
        foreach( $restrictedTypes as $type ) {
            $locIds = array_merge( $locIds, array_keys( $offered[ $type->id ] ) );
        }
        $locations = Location::whereIn( 'id', array_unique( $locIds ) )->get()->keyBy( 'id' );

        foreach( $restrictedTypes as $type ) {
            foreach( array_keys( $offered[ $type->id ] ) as $locId ) {
                if( isset( $seen[ $locId . ':' . $type->id ] ) ) {
                    continue;
                }
                $out[] = (object)[
                    'location'  => $locations[ $locId ] ?? null,
                    'type'      => $type,
                    'sellable'  => 0,
                    'threshold' => (int)$type->low_stock_threshold,
                    'total'     => 0,
                ];
            }
        }

        return $out;
    }
}
