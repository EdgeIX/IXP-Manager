<?php

namespace IXP\Http\Requests\EdgeIX;

use Illuminate\Foundation\Http\FormRequest;

use IXP\Models\Customer;
use IXP\Models\User;

/**
 * Minimal signup form: first name, last name, email, ASN + T&Cs consent.
 *
 * ASN uniqueness against existing customers is checked here.
 * PeeringDB lookup happens in the controller (so we can surface a "register on
 * PeeringDB" message rather than a generic validation error).
 */
class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:user,email',
            'asn'        => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
            ],
            'consent'    => 'accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'      => 'That email address is already registered. Please log in or use a different email.',
            'consent.accepted'  => 'You must agree to the Privacy Policy to continue.',
            'asn.max'           => 'ASN must be a valid 32-bit AS number.',
        ];
    }
}
