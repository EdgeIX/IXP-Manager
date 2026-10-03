<?php

/*
 * EdgeIX ordering Phase 3 — order pipeline settings.
 * See docs/ordering.md "Approval model" and "Orders data model".
 */
return [

    // Approval policy: 'all' = every order auto-approves at placement
    // (zero-touch default), 'existing' = auto only for customers with
    // existing services, 'none' = every order waits for an admin.
    'auto_approve' => env( 'ORDER_AUTO_APPROVE', 'all' ),

    // Days a submitted (unapproved) order holds its port reservation
    // before port-order:expire-holds releases it.
    'hold_days'    => (int)env( 'ORDER_HOLD_DAYS', 14 ),

    // Every placed order emails this address (awareness, not approval —
    // and the manual-billing trigger). Blank = no emails.
    'notify_email' => env( 'ORDER_NOTIFY_EMAIL' ),

];
