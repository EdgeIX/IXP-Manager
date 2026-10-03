<?php

/*
 * EdgeIX ordering Phase 3 — port stock settings.
 *
 * NOTE: deliberately no switch API (eAPI/gNMI) settings here — IXP-Manager
 * has read-only SNMP access to switches and nothing more; see
 * docs/ordering.md "Hard constraint: detection is SNMP-only".
 */
return [

    // Recipient for port-stock:check-levels low-stock digest emails.
    'low_stock_alert_email' => env( 'PORT_STOCK_ALERT_EMAIL' ),

];
