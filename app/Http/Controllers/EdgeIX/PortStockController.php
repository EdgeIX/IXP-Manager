<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\View\View;

use IXP\Http\Controllers\Controller;

use IXP\Models\PortType;
use IXP\Services\EdgeIX\PortStockService;

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
}
