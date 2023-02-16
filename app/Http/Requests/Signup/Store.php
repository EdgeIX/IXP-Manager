<?php

namespace IXP\Http\Requests\Signup;

/*
 * Copyright (C) 2009 - 2021 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use Auth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use IXP\Models\Customer;

/**
 * Customer Store Request
 * @author     Barry O'Donovan <barry@islandbridgenetworks.ie>
 * @author     Yann Robin <yann@islandbridgenetworks.ie>
 * @category   IXP
 * @package    IXP\Http\Requests\Customer
 * @copyright  Copyright (C) 2009 - 2021 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Store extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // middleware ensures superuser access only so always authorised here:
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $validateCommonDetails = [
            'name'                  => 'required|string|max:255',
            'corpwww'               => 'nullable|url|max:255',
            'md5support'            => 'nullable|string|in:'  . implode( ',', array_keys( Customer::$MD5_SUPPORT ) ),
            'abbreviatedName'       => 'required|string|max:30',
        ];

        $validateOtherDetails = [
            'autsys'                => 'int|min:1',
            'maxprefixes'           => 'nullable|int|min:0',
            'peeringemail'          => 'email',
            'peeringmacro'          => 'nullable|string|max:255',
            'peeringmacrov6'        => 'nullable|string|max:255',
            'peeringpolicy'         => 'string|in:' . implode( ',', array_keys( Customer::$PEERING_POLICIES ) ),
            'noc24hphone'           => 'nullable|string|max:255',
            'nocemail'              => 'email|max:255',
            'nochours'              => 'nullable|string|in:' . implode( ',', array_keys( Customer::$NOC_HOURS ) ),
        ];

        return $this-> type === Customer::TYPE_ASSOCIATE  ? $validateCommonDetails : array_merge( $validateCommonDetails, $validateOtherDetails ) ;
    }

    /**
     * Configure the validator instance.
     *
     * @param  Validator  $validator
     *
     * @return void
     */
    public function withValidator( Validator $validator ): void
    {
        $validator->after( function( $validator ) {
        });
    }

}
