<?php

namespace IXP\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EdgeIX ordering Phase 3: one reserved switch port on a port order.
 *
 * These rows ARE the reservation — PortStockService and the placement
 * query exclude switch ports referenced by any OPEN order. The patch
 * panel port is captured at reservation time because it feeds the LOA.
 *
 * @property int $id
 * @property int $port_order_id
 * @property int $switchportid
 * @property int|null $patch_panel_port_id
 */
class PortOrderPort extends Model
{
    protected $table = 'port_order_port';

    protected $fillable = [ 'port_order_id', 'switchportid', 'patch_panel_port_id' ];

    public function portOrder(): BelongsTo
    {
        return $this->belongsTo( PortOrder::class, 'port_order_id' );
    }

    public function switchPort(): BelongsTo
    {
        return $this->belongsTo( SwitchPort::class, 'switchportid' );
    }

    public function patchPanelPort(): BelongsTo
    {
        return $this->belongsTo( PatchPanelPort::class, 'patch_panel_port_id' );
    }
}
