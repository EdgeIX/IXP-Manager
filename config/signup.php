<?php

/**
 * Signup / T&Cs configuration.
 *
 * Values are env-driven so they can be updated via the admin /admin/settings UI
 * (see config/ixp_fe_settings.php → "Signup Terms" panel).
 *
 * When you change the substance of the linked privacy policy, bump
 * SIGNUP_TERMS_VERSION as well — that value is stamped on the customer at
 * consent time and drives re-consent prompts in Phase 2 (MSA gate).
 */

return [

    'terms' => [
        'privacy_url' => env( 'SIGNUP_TERMS_PRIVACY_URL', 'https://edgeix.net/privacy/' ),
        'version'     => env( 'SIGNUP_TERMS_VERSION',     '2026-07-01'                        ),
    ],

];
