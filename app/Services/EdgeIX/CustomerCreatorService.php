<?php

namespace IXP\Services\EdgeIX;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use IXP\Events\User\UserCreated as UserCreatedEvent;

use IXP\Models\CompanyBillingDetail;
use IXP\Models\CompanyRegisteredDetail;
use IXP\Models\Customer;
use IXP\Models\CustomerToUser;
use IXP\Models\IrrdbConfig;
use IXP\Models\User;

/**
 * Shared customer + user creation for the EdgeIX signup flows.
 *
 * Both the manual 4-field signup (SignupController) and the PeeringDB OAuth
 * signup (SignupOAuthController) end up calling this. Keeping the creation
 * logic in one place means the two paths produce identical database state.
 *
 * See [[signup-msa-project]] for the full flow.
 */
class CustomerCreatorService
{
    /**
     * Create a Customer + User pair from PeeringDB network data.
     *
     * @param int    $asn          ASN of the new customer.
     * @param array  $pdbNet       PeeringDB network object (from PeeringDb::getNetworkByAsn
     *                             or from the OAuth token's `networks` array). Missing keys
     *                             are tolerated — we prefer PeeringDB data but fall back to
     *                             safe defaults.
     * @param string $firstName    Signup user's first name.
     * @param string $lastName     Signup user's last name.
     * @param string $email        Signup user's email (also used as username).
     * @param bool   $viaOauth     True if this signup came through the PeeringDB OAuth path
     *                             — sets cust.peeringdb_oauth=1 and user.peeringdb_id.
     * @param ?int   $peeringDbUserId PeeringDB user ID (set when $viaOauth = true).
     * @param bool   $fireWelcomeEmail Fire UserCreatedEvent (welcome + set-password link).
     *                                 Manual signup: true. OAuth signup: false — they're
     *                                 already authenticated via PeeringDB.
     *
     * @return array{customer: Customer, user: User}
     */
    public function create(
        int    $asn,
        array  $pdbNet,
        string $firstName,
        string $lastName,
        string $email,
        bool   $viaOauth         = false,
        ?int   $peeringDbUserId  = null,
        bool   $fireWelcomeEmail = true,
    ): array {

        // Peering policy mapping — PeeringDB uses title case, IXP-M uses lowercase enum
        $peeringPolicyMap = [
            'Open'        => Customer::PEERING_POLICY_OPEN,
            'Selective'   => Customer::PEERING_POLICY_SELECTIVE,
            'Restrictive' => Customer::PEERING_POLICY_SELECTIVE,
            'No'          => Customer::PEERING_POLICY_CLOSED,
        ];
        $peeringPolicy = $peeringPolicyMap[ $pdbNet['policy_general'] ?? '' ] ?? Customer::PEERING_POLICY_OPEN;

        // 20% headroom on prefix limits — mirrors PeeringDbSyncController behaviour
        $maxPrefixes4 = ( (int)( $pdbNet['info_prefixes4'] ?? 0 ) > 0 ) ? (int) ceil( $pdbNet['info_prefixes4'] * 1.2 ) : 100;
        $maxPrefixes6 = ( (int)( $pdbNet['info_prefixes6'] ?? 0 ) > 0 ) ? (int) ceil( $pdbNet['info_prefixes6'] * 1.2 ) : 100;

        // Prefer PeeringDB name, fall back to AS{asn}
        $customerName = !empty( $pdbNet['name'] ) ? $pdbNet['name'] : "AS{$asn}";
        $abbreviated  = !empty( $pdbNet['aka'] ) ? $pdbNet['aka'] : substr( $customerName, 0, 30 );

        // Abbreviated name uniqueness — append ASN on collision
        if( Customer::where( 'abbreviatedName', $abbreviated )->exists() ) {
            $abbreviated = substr( $abbreviated, 0, 24 ) . "-AS{$asn}";
        }

        $creator = env( 'SIGNUP_NAME', $viaOauth ? 'signup-peeringdb' : 'signup' );

        // Pick an IRRDB config to attach to the new customer. cust.irrdb is a
        // nullable FK into irrdbconfig; the reference IXP-M seed uses id=14 but
        // that ID isn't stable across installations. Prefer RADB by source
        // name (most common IRRDB source), else fall back to the first row,
        // else leave null (admin can set later).
        $irrdbId = ( IrrdbConfig::where( 'source', 'RADB' )->first()
                     ?? IrrdbConfig::orderBy( 'id' )->first() )?->id;

        // Wrap the multi-row insert so a partial failure (e.g. FK violation on
        // Customer, or User save failing after Customer succeeded) can't leave
        // an orphaned row behind.
        [ $customer, $user ] = DB::transaction( function () use (
            $customerName, $abbreviated, $pdbNet, $asn, $maxPrefixes4, $maxPrefixes6,
            $peeringPolicy, $creator, $irrdbId, $viaOauth, $email, $firstName, $lastName, $peeringDbUserId
        ) {
            // Upstream customer overview views expect these two rows to exist
            // (e.g. details.foil.php dereferences companyRegisteredDetail->id).
            // Create them with the data we do know from PeeringDB + the signup,
            // leaving the rest blank for the admin to complete.
            $regDetail = CompanyRegisteredDetail::create( [
                'registeredName' => $customerName,
            ] );

            $billDetail = CompanyBillingDetail::create( [
                'billingContactName' => trim( $firstName . ' ' . $lastName ),
                'billingEmail'       => $email,
                'billingTelephone'   => $pdbNet['noc_phone'] ?? null,
                'vatRate'            => '10%',
                'invoiceMethod'      => 'EMAIL',
                'invoiceEmail'       => $email,
            ] );

            $customer = Customer::create( [
                'name'                   => $customerName,
                'abbreviatedName'        => $abbreviated,
                'corpwww'                => $pdbNet['website'] ?? null,
                'autsys'                 => $asn,
                'maxprefixes'            => $maxPrefixes4,
                'maxprefixesv6'          => $maxPrefixes6,
                'peeringmacro'           => $pdbNet['irr_as_set'] ?? null,
                'peeringmacrov6'         => $pdbNet['irr_as_set'] ?? null,
                'peeringpolicy'          => $peeringPolicy,
                'nocphone'               => $pdbNet['noc_phone'] ?? null,
                'nocemail'               => $pdbNet['noc_email'] ?? null,
                'nochours'               => Customer::NOC_HOURS_24x7,
                'created'                => date( 'Y-m-d H:i:s' ),
                'datejoin'               => date( 'Y-m-d' ),
                'dateleave'              => null,
                'status'                 => Customer::STATUS_NOTCONNECTED,
                'type'                   => Customer::TYPE_FULL,
                'activepeeringmatrix'    => 0,
                'creator'                => $creator,
                'irrdb'                  => $irrdbId,
                'md5support'             => Customer::MD5_SUPPORT_UNKNOWN,
                'MD5Support'             => Customer::MD5_SUPPORT_UNKNOWN,
                'peeringdb_oauth'        => $viaOauth ? 1 : 0,
                'terms_version_accepted' => config( 'signup.terms.version' ),
                'company_registered_detail_id' => $regDetail->id,
                'company_billing_details_id'   => $billDetail->id,
            ] );

            // User: username = email; password random (welcome email sends reset link
            // on the manual path; OAuth users authenticate via PeeringDB and can set
            // a local password later from their profile).
            $user = new User;
            $user->username     = $email;
            $user->email        = $email;
            $user->password     = Hash::make( Str::random( 32 ) );
            $user->name         = trim( $firstName . ' ' . $lastName );
            $user->custid       = $customer->id;
            $user->privs        = User::AUTH_CUSTADMIN;
            $user->creator      = $creator;
            $user->peeringdb_id = $viaOauth ? $peeringDbUserId : null;
            $user->save();

            $c2u = new CustomerToUser;
            $c2u->customer_id      = $customer->id;
            $c2u->user_id          = $user->id;
            $c2u->privs            = User::AUTH_CUSTADMIN;
            $c2u->extra_attributes = [ 'created_by' => [ 'type' => $viaOauth ? 'signup-peeringdb' : 'signup' ] ];
            $c2u->save();

            return [ $customer, $user ];
        } );

        // Invalidate the superuser top-bar customer dropdown cache — otherwise
        // admins can't see the new customer for up to an hour (see
        // IxpServiceProvider::register, cache key 'admin_home_customers').
        Cache::forget( 'admin_home_customers' );

        Log::notice( sprintf(
            '[Signup] Created customer %d (%s, AS%d) with user %d (%s) via %s',
            $customer->id, $customer->name, $asn, $user->id, $user->email,
            $viaOauth ? 'PeeringDB OAuth' : 'manual form'
        ) );

        if( $fireWelcomeEmail ) {
            event( new UserCreatedEvent( $user ) );
        }

        return [
            'customer' => $customer,
            'user'     => $user,
        ];
    }
}
