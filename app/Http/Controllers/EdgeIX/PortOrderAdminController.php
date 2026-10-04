<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;

use IXP\Models\PortOrder;

use IXP\Services\EdgeIX\PortOrderService;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * EdgeIX admin: the port order queue (ordering Phase 3).
 *
 * Every order lands here for awareness (and manual billing) regardless of
 * the auto-approve policy; the Approve button only matters for orders the
 * policy left in `submitted` (ORDER_AUTO_APPROVE = existing|none).
 *
 * Superuser-only. See docs/ordering.md.
 */
class PortOrderAdminController extends Controller
{
    public function index( Request $r ): View
    {
        $state = $r->state;

        return view( 'portorder.index', [
            'poOrders' => PortOrder::with( [ 'customer', 'location', 'portType', 'portOrderPorts' ] )
                ->when( $state === 'open' || !$state, fn( $q ) => $q->whereIn( 'state', PortOrder::OPEN_STATES ) )
                ->when( $state && $state !== 'open' && $state !== 'all', fn( $q ) => $q->where( 'state', $state ) )
                ->orderByDesc( 'id' )->limit( 200 )->get(),
            'poState'  => $state ?: 'open',
        ] );
    }

    public function view( PortOrder $order ): View
    {
        return view( 'portorder.view', [
            'poOrder' => $order->load( [ 'customer', 'user', 'location', 'customerLocation', 'portType', 'portOrderPorts.switchPort.switcher', 'portOrderPorts.patchPanelPort.patchPanel' ] ),
        ] );
    }

    public function approve( Request $r, PortOrder $order, PortOrderService $orders ): RedirectResponse
    {
        if( $order->state !== PortOrder::STATE_SUBMITTED ) {
            AlertContainer::push( "Order #{$order->id} is not awaiting approval.", Alert::WARNING );
        } else {
            $orders->approve( $order, $r->user() );
            AlertContainer::push( "Order #{$order->id} approved.", Alert::SUCCESS );
        }

        return redirect()->route( 'port-order-admin@view', [ 'order' => $order->id ] );
    }

    /**
     * BACKORDER fulfilment: capacity has been built — reserve the port(s)
     * and approve in one go.
     */
    public function reserveBackorder( Request $r, PortOrder $order, PortOrderService $orders ): RedirectResponse
    {
        if( $order->state !== PortOrder::STATE_BACKORDER ) {
            AlertContainer::push( "Order #{$order->id} is not a backorder.", Alert::WARNING );
        } else {
            try {
                $orders->reserveBackorder( $order, $r->user() );
                AlertContainer::push( "Order #{$order->id}: port(s) reserved and order approved.", Alert::SUCCESS );
            } catch( \IXP\Exceptions\EdgeIX\InsufficientPortStockException $e ) {
                AlertContainer::push( "Order #{$order->id}: still no sellable stock for "
                    . (int)$order->quantity . " x " . e( $order->portType?->name ?? '?' )
                    . " at this site — check Port Stock (prewired? detected? typed Peering?).", Alert::DANGER );
            }
        }

        return redirect()->route( 'port-order-admin@view', [ 'order' => $order->id ] );
    }

    public function cancel( Request $r, PortOrder $order, PortOrderService $orders ): RedirectResponse
    {
        $r->validate( [ 'cancel_reason' => 'required|string|max:255' ] );

        if( !$order->isOpen() ) {
            AlertContainer::push( "Order #{$order->id} is not open.", Alert::WARNING );
        } else {
            $orders->cancel( $order, $r->cancel_reason, $r->user() );
            AlertContainer::push( "Order #{$order->id} cancelled — reserved port(s) released.", Alert::SUCCESS );
        }

        return redirect()->route( 'port-order-admin@index' );
    }
}
