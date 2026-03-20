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
        $customer = Auth::getUser()->customer;

        return $this->doSync( $customer );
    }

    /**
     * Admin: sync prefix limits for any customer by ID.
     */
    public function syncCustomer( int $id ): JsonResponse
    {
        $customer = Customer::findOrFail( $id );

        return $this->doSync( $customer );
    }

    /**
     * Perform the actual PeeringDB lookup and update.
     */
    private function doSync( Customer $customer ): JsonResponse
    {
        if ( !$customer->autsys ) {
            return response()->json( [ 'error' => 'No ASN configured for this customer.' ], 422 );
        }

        $pdb = app()->make( PeeringDb::class );
        $net = $pdb->getNetworkByAsn( (int) $customer->autsys );

        if ( $net === false ) {
            return response()->json( [
                'error' => $pdb->error ?: 'PeeringDB lookup failed.',
            ], 422 );
        }

        $v4 = isset( $net['info_prefixes4'] ) && $net['info_prefixes4'] > 0
            ? (int) $net['info_prefixes4']
            : null;

        $v6 = isset( $net['info_prefixes6'] ) && $net['info_prefixes6'] > 0
            ? (int) $net['info_prefixes6']
            : null;

        if ( $v4 === null && $v6 === null ) {
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
        }

        return response()->json( [
            'success' => true,
            'changed' => $changed,
            'v4'      => $customer->maxprefixes,
            'v6'      => $customer->maxprefixesv6,
        ] );
    }
}
