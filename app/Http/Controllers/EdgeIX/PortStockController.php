<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;

use IXP\Models\Location;
use IXP\Models\PortType;
use IXP\Services\EdgeIX\PortStockService;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * EdgeIX admin: sellable port stock (ordering Phase 3).
 *
 * The matrix the order form's availability logic will be built on — the
 * stock/sellable definitions live in PortStockService (shared with the
 * low-stock alerter, port-stock:check-levels).
 *
 * Also surfaces the hygiene buckets so stock data gets fixed where it's
 * wrong: free ports whose panel side is NOT marked prewired (invisible to
 * ordering until fixed), and detected optics with no catalogue match.
 *
 * Superuser-only. See docs/ordering.md.
 */
class PortStockController extends Controller
{
    public function index( PortStockService $stock ): View
    {
        $rows = $stock->rows();

        // Same logic as the low-stock alerter — the matrix badges what the
        // 09:00 digest would report, keyed "locId:typeId".
        $stockLow = [];
        foreach( $stock->shortfalls( $rows ) as $s ) {
            $stockLow[ ( $s->location?->id ?? 0 ) . ':' . $s->type->id ] = $s;
        }

        return view( 'porttype.stock', [
            'stockLow'         => $stockLow,
            'stockMatrix'      => $stock->sellableMatrix( $rows ),
            'stockLocations'   => $rows->pluck( 'location' )->filter()->unique( 'id' )->sortBy( 'name' )->values(),
            'stockTypes'       => PortType::active()->orderBy( 'speed' )->orderBy( 'name' )->get(),
            'stockSellable'    => $rows->where( 'sellable', true )->sortBy( fn( $r ) => [ $r->location?->name, $r->sp->ifName ] )->values(),
            'stockNeedsPrewire'=> $rows->where( 'needsPrewireFlag', true )->sortBy( fn( $r ) => [ $r->location?->name, $r->sp->ifName ] )->values(),
            'stockUnmatched'   => $rows->where( 'unmatchedOptic', true )->sortBy( fn( $r ) => [ $r->location?->name, $r->sp->ifName ] )->values(),
        ] );
    }

    /**
     * Served-via mapping: mark passive/campus sites as served from a
     * demarc site so campus customers can find us on the order form.
     */
    public function servedVia(): View
    {
        return view( 'porttype.servedvia', [
            'svLocations' => Location::orderBy( 'name' )->get(),
        ] );
    }

    public function saveServedVia( Request $r ): RedirectResponse
    {
        $r->validate( [
            'served'   => 'nullable|array',
            'served.*' => 'nullable|integer|exists:location,id',
        ] );

        foreach( Location::all() as $loc ) {
            $via = (int)( $r->input( 'served.' . $loc->id ) ?: 0 ) ?: null;

            // No self-reference, and no chaining onto another alias.
            if( $via === $loc->id ) {
                $via = null;
            }
            if( $via && Location::find( $via )?->served_via_locationid ) {
                AlertContainer::push( e( $loc->name ) . ": can't serve via a site that is itself served-via — pick the demarc site.", Alert::DANGER );
                continue;
            }

            if( (int)$loc->served_via_locationid !== (int)$via ) {
                $loc->served_via_locationid = $via;
                $loc->save();
            }
        }

        AlertContainer::push( 'Served-via mapping saved.', Alert::SUCCESS );
        return redirect()->route( 'port-stock@served-via' );
    }
}
