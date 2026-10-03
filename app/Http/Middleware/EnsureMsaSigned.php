<?php

namespace IXP\Http\Middleware;

use Closure;

use Illuminate\Http\Request;

use IXP\Models\Customer;

/**
 * EdgeIX: MSA gate for service ordering.
 *
 * ONE gate, at order time, uniform for everyone: new signups browse the
 * portal freely and hit this when they first try to order; existing
 * customers with no MSA on record hit the same gate at their next order —
 * self-retroactive, no backfill needed.
 *
 * Applied as route middleware ('msa') on the /order route group only.
 * Redirects to the /msa page, which branches on the customer's state
 * (standard unsigned → acceptance flow; custom in negotiation → contact
 * sales; signed → never redirected here).
 *
 * Superusers bypass — admins place orders on behalf of customers through
 * the provisioning tooling, not this gate.
 *
 * See docs/ordering.md.
 */
class EnsureMsaSigned
{
    public function handle( Request $request, Closure $next )
    {
        $user = $request->user();

        if( $user && $user->isSuperUser() ) {
            return $next( $request );
        }

        /** @var Customer|null $cust */
        $cust = $user?->customer;

        // Exempt types (internal, pro-bono) have no MSA to execute.
        if( $cust && ( !$cust->msaRequired() || $cust->msaSigned() ) ) {
            return $next( $request );
        }

        return redirect()->route( 'msa@index' );
    }
}
