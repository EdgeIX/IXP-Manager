<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;
use IXP\Http\Requests\EdgeIX\SignupRequest;

use IXP\Models\Customer;

use IXP\Services\EdgeIX\CustomerCreatorService;
use IXP\Services\PeeringDb;

/**
 * EdgeIX minimal signup flow (manual form).
 *
 * Sibling of SignupOAuthController which handles the PeeringDB OAuth path.
 * Both controllers share Customer + User creation via CustomerCreatorService.
 *
 *   1. First/last name + email + ASN + T&Cs consent.
 *   2. ASN uniqueness check against cust.autsys.
 *   3. PeeringDB lookup — REQUIRED. No PeeringDB entry = blocked.
 *   4. CustomerCreatorService creates Customer + User + pivot + fires the
 *      welcome email with a set-password link.
 *   5. Redirect to /signup/thanks.
 *
 * See [[signup-msa-project]] for full context.
 */
class SignupController extends Controller
{
    public function __construct( private readonly CustomerCreatorService $creator )
    {
        $this->middleware( 'guest' );
    }

    public function create(): View
    {
        return view( 'signup.create' );
    }

    public function store( SignupRequest $r ): RedirectResponse
    {
        $asn = (int) $r->input( 'asn' );

        if( Customer::where( 'autsys', $asn )->exists() ) {
            return redirect()->route( 'signup@create' )
                ->withInput()
                ->withErrors( [
                    'asn' => "An account for AS{$asn} already exists. Please contact your account administrator to be added, "
                            . "or email support@edgeix.net.au if you can't reach them.",
                ] );
        }

        $net = app( PeeringDb::class )->getNetworkByAsn( $asn );

        if( $net === false ) {
            return redirect()->route( 'signup@create' )
                ->withInput()
                ->withErrors( [
                    'asn' => "We couldn't find AS{$asn} on PeeringDB. Please register your network at "
                            . "https://www.peeringdb.com/register first — a PeeringDB entry is required to peer at EdgeIX. "
                            . "Once your entry is live, come back and try again.",
                ] );
        }

        $this->creator->create(
            asn:              $asn,
            pdbNet:           $net,
            firstName:        $r->input( 'first_name' ),
            lastName:         $r->input( 'last_name' ),
            email:            $r->input( 'email' ),
            username:         $r->input( 'username' ),
            viaOauth:         false,
            fireWelcomeEmail: true,
        );

        return redirect()->route( 'signup@thanks' );
    }

    public function thanks(): View
    {
        return view( 'signup.thanks' );
    }
}
