<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\View\View;

use IXP\Http\Controllers\Controller;

use IXP\Models\PatchPanelPort;
use IXP\Models\PortType;
use IXP\Models\SwitchPort;

/**
 * EdgeIX admin: sellable port stock (ordering Phase 3).
 *
 * The matrix the order form's availability logic will be built on:
 * per (DC/location × port type) counts of sellable ports, where sellable =
 * active peering switch port + effective port type + no physical interface
 * + patch panel port in PREWIRED state.
 *
 * Also surfaces the hygiene buckets so stock data gets fixed where it's
 * wrong: free ports whose panel side is NOT marked prewired (invisible to
 * ordering until fixed), and detected optics with no catalogue match.
 *
 * Superuser-only. See docs/ordering.md.
 */
class PortStockController extends Controller
{
    public function index(): View
    {
        $ports = SwitchPort::with( [ 'switcher.cabinet.location', 'patchPanelPort.patchPanel', 'physicalInterface', 'portType', 'portTypeOverride' ] )
            ->where( 'active', true )
            ->where( 'type', SwitchPort::TYPE_PEERING )
            ->whereHas( 'switcher', fn( $q ) => $q->where( 'active', true ) )
            ->get();

        $rows = $ports->map( function( SwitchPort $sp ) {
            $type = $sp->effectivePortType();
            $ppp  = $sp->patchPanelPort;
            $free = !$sp->physicalInterface;

            return (object)[
                'sp'        => $sp,
                'location'  => $sp->switcher?->cabinet?->location,
                'type'      => $type,
                'free'      => $free,
                'prewired'  => $ppp && (int)$ppp->state === PatchPanelPort::STATE_PREWIRED,
                'ppp'       => $ppp,
                // sellable = what the order form may offer
                'sellable'  => $free && $type && $type->active
                               && $ppp && (int)$ppp->state === PatchPanelPort::STATE_PREWIRED,
                // hygiene: free port with a type but panel side not prewired
                'needsPrewireFlag' => $free && $type && $type->active
                               && !( $ppp && (int)$ppp->state === PatchPanelPort::STATE_PREWIRED ),
                // hygiene: optic detected but no catalogue match, port free
                'unmatchedOptic'   => $free && !$type && $sp->detected_xcvr,
            ];
        } );

        $activeTypes = PortType::active()->orderBy( 'speed' )->orderBy( 'name' )->get();

        // location id → [ port type id → sellable count ]
        $matrix = [];
        foreach( $rows->where( 'sellable', true ) as $r ) {
            $locId = $r->location?->id ?? 0;
            $matrix[ $locId ][ $r->type->id ] = ( $matrix[ $locId ][ $r->type->id ] ?? 0 ) + 1;
        }

        $locations = $rows->pluck( 'location' )->filter()->unique( 'id' )->sortBy( 'name' )->values();

        return view( 'porttype.stock', [
            'stockMatrix'      => $matrix,
            'stockLocations'   => $locations,
            'stockTypes'       => $activeTypes,
            'stockSellable'    => $rows->where( 'sellable', true )->sortBy( fn( $r ) => [ $r->location?->name, $r->sp->ifName ] )->values(),
            'stockNeedsPrewire'=> $rows->where( 'needsPrewireFlag', true )->sortBy( fn( $r ) => [ $r->location?->name, $r->sp->ifName ] )->values(),
            'stockUnmatched'   => $rows->where( 'unmatchedOptic', true )->sortBy( fn( $r ) => [ $r->location?->name, $r->sp->ifName ] )->values(),
        ] );
    }
}
