<?php

namespace IXP\Exceptions\EdgeIX;

/**
 * EdgeIX ordering: thrown by PortOrderService::place() when no sellable
 * prewired port(s) of the requested type exist at the requested location
 * (for LAG quantities: not enough on any single switch). The order form
 * catches this and offers the lead-time path instead; admins are alerted
 * separately by the low-stock job.
 */
class InsufficientPortStockException extends \RuntimeException
{
}
