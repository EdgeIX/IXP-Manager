<?php

namespace IXP\Console\Commands\EdgeIX;

use Illuminate\Console\Command;

use IXP\Services\EdgeIX\PortOrderService;

/**
 * EdgeIX ordering Phase 3: expire submitted orders whose approval hold
 * (port_order.reserved_until) has run out, releasing their reserved
 * ports back into sellable stock. Only matters when the approval policy
 * gates orders (ORDER_AUTO_APPROVE != all); scheduled hourly regardless —
 * it's a no-op when nothing is overdue.
 */
class ExpirePortOrderHolds extends Command
{
    protected $signature   = 'port-order:expire-holds';
    protected $description = 'EdgeIX: expire overdue unapproved port orders and release their reserved ports';

    public function handle( PortOrderService $orders ): int
    {
        $n = $orders->expireHolds();

        $this->info( $n ? "{$n} overdue order(s) expired, ports released." : 'No overdue holds.' );

        return self::SUCCESS;
    }
}
