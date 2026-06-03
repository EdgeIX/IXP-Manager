<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale router alert email
    |--------------------------------------------------------------------------
    |
    | When set, the router:check-stale command will email this address when
    | route servers haven't synced within the threshold or have a stuck lock.
    | Scheduled hourly from Console\Kernel.
    |
    | Leave unset to disable the alert.
    |
    */

    'stale_alert_email' => env('ROUTER_STALE_ALERT_EMAIL', null),

];
