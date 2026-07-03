<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

use Laravel\Socialite\Facades\Socialite;

use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

use IXP\Http\Controllers\Controller;

use IXP\Models\Customer;
use IXP\Models\User;

use IXP\Services\EdgeIX\CustomerCreatorService;
use IXP\Services\PeeringDb;

use IXP\Utils\View\Alert\{
    Alert,
    Container as AlertContainer,
};

/**
 * EdgeIX signup via PeeringDB OAuth.
 *
 * Flow:
 *   1. GET  /signup/peeringdb           — redirect to PeeringDB OAuth.
 *   2. GET  /signup/peeringdb/callback  — receive user + admin networks, classify
 *                                          each ASN against IXP-M's cust table, stash
 *                                          the eligible list in session, render picker.
 *   3. POST /signup/peeringdb/confirm   — user picked an ASN + ticked consent →
 *                                          create customer via CustomerCreatorService,
 *                                          log them in, redirect to dashboard.
 *
 * The existing UserAggregator::findOrCreateFromPeeringDb (used by LoginController)
 * assumes the customer already exists and DELETES the user if no match — great
 * for login, blocks signup. This controller bypasses it and creates from scratch.
 *
 * Perms bitmask on each network:
 *   0x01 read, 0x02 update, 0x04 create, 0x08 delete.
 *   We require perms & 0x02 (update) or higher to consider the user an
 *   authorised signatory for that ASN — a read-only viewer shouldn't be able
 *   to create a customer account on the network's behalf.
 *
 * Session keys used (all cleared after use):
 *   ixpm_pdb_signup.pdb_user_id      — PeeringDB user ID (int)
 *   ixpm_pdb_signup.pdb_first_name   — from given_name
 *   ixpm_pdb_signup.pdb_last_name    — from family_name
 *   ixpm_pdb_signup.pdb_email        — verified email
 *   ixpm_pdb_signup.eligible_networks — array of [asn, name, id]
 *   ixpm_pdb_signup.info_networks     — array of ["already registered" info]
 *
 * See [[signup-msa-project]] for full context.
 */
class SignupOAuthController extends Controller
{
    private const SESSION_KEY = 'ixpm_pdb_signup';

    public function __construct( private readonly CustomerCreatorService $creator )
    {
        $this->middleware( 'guest' );
    }

    /**
     * Kick off the PeeringDB OAuth handshake.
     */
    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        if( !config( 'auth.peeringdb.enabled' ) ) {
            AlertContainer::push( 'Sign up with PeeringDB is not enabled.', Alert::DANGER );
            return redirect()->route( 'signup@create' );
        }

        return Socialite::driver( 'peeringdb' )->redirect();
    }

    /**
     * Callback from PeeringDB. Classify their networks, then either error out
     * or render the picker with the T&Cs consent step.
     */
    public function callback( Request $r ): View|RedirectResponse
    {
        if( !config( 'auth.peeringdb.enabled' ) ) {
            return redirect()->route( 'signup@create' );
        }

        try {
            $suser = Socialite::driver( 'peeringdb' )->user();
        } catch( \Throwable $e ) {
            Log::warning( '[SignupOAuth] Socialite failed: ' . $e->getMessage() );
            AlertContainer::push( 'PeeringDB sign-in failed. Please try again or use the manual form.', Alert::DANGER );
            return redirect()->route( 'signup@create' );
        }

        // Shape from LoginController comments: $suser->user is an array with
        // id, given_name, family_name, name, verified_user, verified_email,
        // email, networks[].
        if( !$suser || !isset( $suser->user ) || !is_array( $suser->user ) ) {
            AlertContainer::push( 'PeeringDB returned an unexpected response. Please try again or use the manual form.', Alert::DANGER );
            return redirect()->route( 'signup@create' );
        }

        $pdbUser = $suser->user;

        if( empty( $pdbUser['verified_user'] ) || empty( $pdbUser['verified_email'] ) ) {
            AlertContainer::push(
                'Your PeeringDB user or email address is not verified. Please complete your PeeringDB '
                . 'account registration first, then try signing up again.',
                Alert::WARNING
            );
            return redirect()->route( 'signup@create' );
        }

        $networks = $pdbUser['networks'] ?? [];
        if( !is_array( $networks ) || empty( $networks ) ) {
            AlertContainer::push(
                'Your PeeringDB account is not affiliated with any network. Add your network on PeeringDB '
                . 'first (or ask an existing admin to add you), then try again — or use the manual form.',
                Alert::WARNING
            );
            return redirect()->route( 'signup@create' );
        }

        // Classify each network the OAuth user administers.
        $eligible = [];   // brand new customer possible
        $existingInfo = [];  // asn already in IXP-M — inform the user

        foreach( $networks as $net ) {
            $asn = (int)( $net['asn'] ?? 0 );
            if( $asn <= 0 ) {
                continue;
            }
            // Require update permission or higher — a read-only affiliate shouldn't
            // be able to create a customer record for the ASN.
            $perms = (int)( $net['perms'] ?? 0 );
            if( ( $perms & 0x02 ) !== 0x02 ) {
                continue;
            }

            $existing = Customer::where( 'autsys', $asn )->first();
            if( $existing ) {
                $existingInfo[] = [
                    'asn'          => $asn,
                    'name'         => $existing->name,
                    'oauth_ready'  => (bool) $existing->peeringdb_oauth,
                ];
                continue;
            }

            $eligible[] = [
                'asn'         => $asn,
                'name'        => $net['name'] ?? "AS{$asn}",
                'pdb_net_id'  => (int)( $net['id'] ?? 0 ),
            ];
        }

        if( empty( $eligible ) ) {
            // Nothing to sign up for. Give them a useful message.
            $msg = 'None of your PeeringDB-affiliated networks are eligible for signup.';
            foreach( $existingInfo as $info ) {
                if( $info['oauth_ready'] ) {
                    $msg .= " AS{$info['asn']} ({$info['name']}) already exists — please log in with PeeringDB instead.";
                } else {
                    $msg .= " AS{$info['asn']} ({$info['name']}) is already registered — contact your account administrator.";
                }
            }
            AlertContainer::push( $msg, Alert::WARNING );
            return redirect()->route( 'signup@create' );
        }

        // Stash the OAuth data + shortlist in the session so the confirm step can
        // finish the job without re-hitting PeeringDB.
        session( [
            self::SESSION_KEY => [
                'pdb_user_id'        => (int)( $pdbUser['id'] ?? 0 ),
                'pdb_first_name'     => $pdbUser['given_name']  ?? '',
                'pdb_last_name'      => $pdbUser['family_name'] ?? '',
                'pdb_email'          => $pdbUser['email']       ?? '',
                'eligible_networks'  => $eligible,
                'info_networks'      => $existingInfo,
            ],
        ] );

        return view( 'signup.pick-asn', [
            'eligible'    => $eligible,
            'infoOnly'    => $existingInfo,
            'firstName'   => $pdbUser['given_name']  ?? '',
            'lastName'    => $pdbUser['family_name'] ?? '',
            'email'       => $pdbUser['email']       ?? '',
        ] );
    }

    /**
     * User picked an ASN and ticked consent — create the customer and log them in.
     */
    public function confirm( Request $r ): RedirectResponse
    {
        if( !config( 'auth.peeringdb.enabled' ) ) {
            return redirect()->route( 'signup@create' );
        }

        $stash = session( self::SESSION_KEY );
        if( !$stash || empty( $stash['eligible_networks'] ) ) {
            AlertContainer::push( 'Your signup session expired. Please start again.', Alert::WARNING );
            return redirect()->route( 'signup@create' );
        }

        $r->validate( [
            'asn'     => 'required|integer|min:1',
            'consent' => 'accepted',
        ], [
            'consent.accepted' => 'You must agree to the Privacy Policy, Site Terms and Acceptable Use Policy to continue.',
        ] );

        $asn = (int) $r->input( 'asn' );

        // Confirm the picked ASN is one of the OAuth-eligible ones — defence
        // against tampering with the form.
        $picked = collect( $stash['eligible_networks'] )->firstWhere( 'asn', $asn );
        if( !$picked ) {
            AlertContainer::push( 'That ASN isn\'t in your PeeringDB-eligible list. Please start again.', Alert::DANGER );
            session()->forget( self::SESSION_KEY );
            return redirect()->route( 'signup@create' );
        }

        // Race: another signup might have grabbed the ASN since the picker rendered.
        if( Customer::where( 'autsys', $asn )->exists() ) {
            AlertContainer::push( "AS{$asn} was registered by someone else while you were signing up. Please contact support.", Alert::DANGER );
            session()->forget( self::SESSION_KEY );
            return redirect()->route( 'signup@create' );
        }

        // Refresh the network's PeeringDB data (the OAuth token's network payload
        // is thin — we want the same rich fields the manual form uses).
        $net = app( PeeringDb::class )->getNetworkByAsn( $asn );
        if( $net === false ) {
            // Extremely unlikely (they just came from PeeringDB) but handle it.
            $net = [
                'name'          => $picked['name'],
                'aka'           => null,
                'website'       => null,
                'irr_as_set'    => null,
                'info_prefixes4'=> 0,
                'info_prefixes6'=> 0,
                'policy_general'=> 'Open',
            ];
        }

        $result = $this->creator->create(
            asn:              $asn,
            pdbNet:           $net,
            firstName:        $stash['pdb_first_name'] ?: 'PeeringDB',
            lastName:         $stash['pdb_last_name']  ?: "User {$stash['pdb_user_id']}",
            email:            $stash['pdb_email'],
            viaOauth:         true,
            peeringDbUserId:  (int) $stash['pdb_user_id'],
            fireWelcomeEmail: false,  // authenticated via PeeringDB — no set-password step
        );

        session()->forget( self::SESSION_KEY );

        // Log them straight in — they proved identity via PeeringDB OAuth.
        Auth::login( $result['user'] );
        session( [ 'ixpm_external_2fa_completed' => true ] );

        AlertContainer::push(
            "Welcome to " . config( 'identity.sitename' ) . "! Your account for AS{$asn} has been created.",
            Alert::SUCCESS
        );

        return redirect( '/' );
    }
}
