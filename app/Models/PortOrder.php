<?php

namespace IXP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EdgeIX ordering Phase 3: a customer port order.
 *
 * State machine: submitted → approved → provisioned → awaiting_xconnect →
 * active; terminal cancelled / expired. Open states hold their port
 * reservation (the portOrderPorts rows) — the stock queries exclude those
 * switch ports. See docs/ordering.md "Orders data model".
 *
 * @property int $id
 * @property int $custid
 * @property int|null $user_id
 * @property string $state
 * @property int $locationid
 * @property int $port_type_id
 * @property int $quantity
 * @property bool $tagged
 * @property int|null $vlan_tag
 * @property array|null $macs
 * @property string|null $delivery_contact
 * @property string|null $po_number
 * @property \Illuminate\Support\Carbon|null $preferred_golive
 * @property \Illuminate\Support\Carbon|null $reserved_until
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $virtual_interface_id
 */
class PortOrder extends Model
{
    protected $table = 'port_order';

    // Order kinds — same table/state machine/reservation engine, different
    // picker constraints and provisioning recipe (see docs/ordering.md):
    //   new_port — full service: diversity-preferred picker, full recipe.
    //   add_lag  — add member(s) to an existing LAG: picker CONSTRAINED to
    //              the LAG's own switch, provisioning adds PIs only.
    //   upgrade  — speed swap on an existing service: new-type port, IP
    //              swing, cutover window, old port decommissioned.
    public const KIND_NEW_PORT = 'new_port';
    public const KIND_ADD_LAG  = 'add_lag';
    public const KIND_UPGRADE  = 'upgrade';

    public const STATE_SUBMITTED         = 'submitted';
    public const STATE_APPROVED          = 'approved';
    public const STATE_PROVISIONED       = 'provisioned';
    public const STATE_AWAITING_XCONNECT = 'awaiting_xconnect';
    public const STATE_ACTIVE            = 'active';
    public const STATE_CANCELLED         = 'cancelled';
    public const STATE_EXPIRED           = 'expired';

    /** States that hold their port reservation. */
    public const OPEN_STATES = [
        self::STATE_SUBMITTED,
        self::STATE_APPROVED,
        self::STATE_PROVISIONED,
        self::STATE_AWAITING_XCONNECT,
    ];

    protected $fillable = [
        'custid', 'user_id', 'kind', 'target_virtual_interface_id', 'state',
        'locationid', 'port_type_id', 'quantity', 'tagged', 'vlan_tag',
        'macs', 'delivery_contact', 'po_number', 'preferred_golive',
        'admin_notes', 'reserved_until',
    ];

    protected $casts = [
        'tagged'           => 'boolean',
        'macs'             => 'array',
        'preferred_golive' => 'date',
        'reserved_until'   => 'datetime',
        'approved_at'      => 'datetime',
        'provisioned_at'   => 'datetime',
        'loa_issued_at'    => 'datetime',
        'activated_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class, 'custid' );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo( User::class, 'user_id' );
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo( Location::class, 'locationid' );
    }

    public function portType(): BelongsTo
    {
        return $this->belongsTo( PortType::class, 'port_type_id' );
    }

    public function portOrderPorts(): HasMany
    {
        return $this->hasMany( PortOrderPort::class, 'port_order_id' );
    }

    /**
     * The existing service an add_lag / upgrade order targets.
     */
    public function targetVirtualInterface(): BelongsTo
    {
        return $this->belongsTo( VirtualInterface::class, 'target_virtual_interface_id' );
    }

    public function scopeOpen( Builder $q ): Builder
    {
        return $q->whereIn( 'state', self::OPEN_STATES );
    }

    public function isOpen(): bool
    {
        return in_array( $this->state, self::OPEN_STATES, true );
    }

    /**
     * Ordering can proceed to provisioning-complete, but the port can't go
     * live without a MAC (the form promises a nag, not a block, at order
     * time).
     */
    public function macMissing(): bool
    {
        return empty( $this->macs );
    }
}
