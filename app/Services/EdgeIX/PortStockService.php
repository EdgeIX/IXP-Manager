<?php

namespace IXP\Services\EdgeIX;

use Illuminate\Support\Collection;

use IXP\Models\PatchPanelPort;
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
    public function rows(): Collection
    {
        return SwitchPort::with( [ 'switcher.cabinet.location', 'patchPanelPort.patchPanel', 'physicalInterface', 'portType', 'portTypeOverride' ] )
            ->where( 'active', true )
            ->where( 'type', SwitchPort::TYPE_PEERING )
            ->whereHas( 'switcher', fn( $q ) => $q->where( 'active', true ) )
            ->get()
            ->map( function( SwitchPort $sp ) {
                $type = $sp->effectivePortType();
                $ppp  = $sp->patchPanelPort;
                $free = !$sp->physicalInterface;
                $prewired = $ppp && (int)$ppp->state === PatchPanelPort::STATE_PREWIRED;

                return (object)[
                    'sp'        => $sp,
                    'location'  => $sp->switcher?->cabinet?->location,
                    'type'      => $type,
                    'free'      => $free,
                    'prewired'  => $prewired,
                    'ppp'       => $ppp,
                    // sellable = what the order form may offer
                    'sellable'  => $free && $type && $type->active && $prewired,
                    // hygiene: free port with a sellable type but panel side not prewired
                    'needsPrewireFlag' => $free && $type && $type->active && !$prewired,
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
     * Low-stock shortfalls: one entry per (location × type) where the type
     * has a threshold, the location actually DEPLOYS that type (any port of
     * that effective type exists there, in service or not — so locations
     * that never stock a type stay silent), and sellable count < threshold.
     *
     * @return array<int, object{location: mixed, type: mixed, sellable: int, threshold: int, total: int}>
     */
    public function shortfalls( Collection $rows ): array
    {
        $out = [];

        $byLoc = $rows->filter( fn( $r ) => $r->type && $r->type->active && $r->type->low_stock_threshold !== null )
            ->groupBy( fn( $r ) => ( $r->location?->id ?? 0 ) . ':' . $r->type->id );

        foreach( $byLoc as $group ) {
            $first    = $group->first();
            $sellable = $group->where( 'sellable', true )->count();

            if( $sellable < (int)$first->type->low_stock_threshold ) {
                $out[] = (object)[
                    'location'  => $first->location,
                    'type'      => $first->type,
                    'sellable'  => $sellable,
                    'threshold' => (int)$first->type->low_stock_threshold,
                    'total'     => $group->count(),
                ];
            }
        }

        return $out;
    }
}
