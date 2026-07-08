<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;

/**
 * EdgeIX customer order flows — placeholder controller.
 *
 * When Phase 2 (MSA gate) lands, this becomes the entry point that:
 *   1. Checks the customer's MSA status.
 *   2. Redirects to the MSA sign flow if unsigned.
 *   3. Otherwise routes to the appropriate order sub-flow.
 *
 * Phase 3 will implement the actual order flows:
 *   - order.new       — new port (same or new location)
 *   - order.lag       — add port to LAG / convert existing port to LAG
 *   - order.upgrade   — upgrade existing port speed (IP swing)
 *
 * See docs/ordering.md for the workflow spec.
 */
class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware( 'auth' );
    }

    /**
     * Landing page for the Order Port button — currently a "Coming Soon"
     * placeholder that describes what's coming and where to email in the
     * meantime.
     */
    public function index(): View
    {
        return view( 'order.index', [
            'cust' => Auth::getUser()?->customer,
        ] );
    }
}
