<?php

namespace IXP\Http\Controllers;

/*
 * Copyright (C) 2009 - 2024 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public Licence as published by the Free
 * Software Foundation, version v2.0 of the Licence.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public Licence for more details.
 *
 * You should have received a copy of the GNU General Public Licence v2.0
 * along with IXP Manager. If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

use IXP\Models\Customer;
use IXP\Services\PeeringDb;

/**
 * PeeringDbSyncController
 *
 * Syncs a single customer's prefix limits (info_prefixes4/6) from PeeringDB
 * into their IXP-Manager maxprefixes/maxprefixesv6 fields.
 *
 * Endpoints:
 *   POST /dashboard/peeringdb/sync-prefixes     — customer syncs their own data
 *   POST /customer/{id}/peeringdb/sync-prefixes — admin syncs any customer
 */
class PeeringDbSyncController extends Controller
{
    /**
     * Customer self-service: sync their own PeeringDB prefix limits.
     */
    public function syncOwn(): JsonResponse
    {
        $user     = Auth::getUser();
        $customer = $user->customer;

        Log::info( 'PeeringDB prefix sync initiated by customer user', [
            'user_id'     => $user->id,
            'username'    => $user->username,
            'customer_id' => $customer->id,
            'customer'    => $customer->name,
            'asn'         => $customer->autsys,
        ] );

        return $this->doSync( $customer, $user->username );
    }

    /**
     * Admin: sync prefix limits for any customer by ID.
     */
    public function syncCustomer( int $id ): JsonResponse
    {
        $user     = Auth::getUser();
        $customer = Customer::findOrFail( $id );

        Log::info( 'PeeringDB prefix sync initiated by admin', [
            'admin_user_id' => $user->id,
            'admin'         => $user->username,
            'customer_id'   => $customer->id,
            'customer'      => $customer->name,
            'asn'           => $customer->autsys,
        ] );

        return $this->doSync( $customer, $user->username );
    }

    /**
     * Perform the actual PeeringDB lookup and update.
     */
    private function doSync( Customer $customer, string $initiatedBy ): JsonResponse
    {
        if ( !$customer->autsys ) {
            Log::warning( 'PeeringDB prefix sync failed: no ASN', [
                'initiated_by' => $initiatedBy,
                'customer_id'  => $customer->id,
            ] );
            return response()->json( [ 'error' => 'No ASN configured for this customer.' ], 422 );
        }

        $pdb = app()->make( PeeringDb::class );
        $net = $pdb->getNetworkByAsn( (int) $customer->autsys );

        if ( $net === false ) {
            $error = $pdb->error ?: 'PeeringDB lookup failed.';
            Log::warning( 'PeeringDB prefix sync failed: API error', [
                'initiated_by' => $initiatedBy,
                'customer'     => $customer->name,
                'asn'          => $customer->autsys,
                'error'        => $error,
            ] );
            return response()->json( [ 'error' => $error ], 422 );
        }

        $v4 = isset( $net['info_prefixes4'] ) && $net['info_prefixes4'] > 0
            ? (int) $net['info_prefixes4']
            : null;

        $v6 = isset( $net['info_prefixes6'] ) && $net['info_prefixes6'] > 0
            ? (int) $net['info_prefixes6']
            : null;

        if ( $v4 === null && $v6 === null ) {
            Log::info( 'PeeringDB prefix sync: no prefix limits in PeeringDB', [
                'initiated_by' => $initiatedBy,
                'customer'     => $customer->name,
                'asn'          => $customer->autsys,
            ] );
            return response()->json( [
                'warning' => 'PeeringDB has no prefix limits set for AS' . $customer->autsys . '. No changes made.',
                'v4'      => $customer->maxprefixes,
                'v6'      => $customer->maxprefixesv6,
            ] );
        }

        $changed = [];

        if ( $v4 !== null && $customer->maxprefixes !== $v4 ) {
            $changed['IPv4'] = [ 'from' => $customer->maxprefixes, 'to' => $v4 ];
            $customer->maxprefixes = $v4;
        }

        if ( $v6 !== null && $customer->maxprefixesv6 !== $v6 ) {
            $changed['IPv6'] = [ 'from' => $customer->maxprefixesv6, 'to' => $v6 ];
            $customer->maxprefixesv6 = $v6;
        }

        if ( !empty( $changed ) ) {
            $customer->save();
            Log::info( 'PeeringDB prefix sync: updated', [
                'initiated_by' => $initiatedBy,
                'customer'     => $customer->name,
                'asn'          => $customer->autsys,
                'changed'      => $changed,
            ] );
        } else {
            Log::info( 'PeeringDB prefix sync: already up to date', [
                'initiated_by' => $initiatedBy,
                'customer'     => $customer->name,
                'asn'          => $customer->autsys,
                'v4'           => $v4,
                'v6'           => $v6,
            ] );
        }

        return response()->json( [
            'success' => true,
            'changed' => $changed,
            'v4'      => $customer->maxprefixes,
            'v6'      => $customer->maxprefixesv6,
        ] );
    }
}
