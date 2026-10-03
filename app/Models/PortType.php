<?php

namespace IXP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EdgeIX: admin-managed catalogue of sellable port types (ordering Phase 3).
 *
 * `match_patterns` holds one case-insensitive regex per line; the
 * transceiver detection job (switch:detect-transceivers) tests each active
 * type in `priority` order (lowest first) against the detected transceiver
 * model + description and stamps the first match onto the switch port.
 *
 * See docs/ordering.md.
 *
 * @property int $id
 * @property string $name
 * @property int $speed  Mbps (ifHighSpeed convention)
 * @property bool $active
 * @property int $priority
 * @property string|null $match_patterns
 * @property int|null $low_stock_threshold
 * @property string|null $notes
 */
class PortType extends Model
{
    protected $table = 'port_type';

    protected $fillable = [
        'name',
        'speed',
        'active',
        'priority',
        'match_patterns',
        'low_stock_threshold',
        'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function switchPorts(): HasMany
    {
        return $this->hasMany( SwitchPort::class, 'port_type_id' );
    }

    public function scopeActive( Builder $query ): Builder
    {
        return $query->where( 'active', true );
    }

    /**
     * Does the given transceiver model/description string match this type?
     *
     * Each non-empty line of match_patterns is a case-insensitive regex.
     * Invalid regexes are skipped (the admin form validates, but belt and
     * braces — a bad pattern must not take down the detection run).
     */
    public function matches( string $xcvr ): bool
    {
        foreach( preg_split( '/\r\n|\r|\n/', (string)$this->match_patterns ) as $pattern ) {
            $pattern = trim( $pattern );
            if( $pattern === '' ) {
                continue;
            }
            if( @preg_match( '/' . str_replace( '/', '\/', $pattern ) . '/i', $xcvr ) === 1 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map a detected transceiver string to the first matching type, in
     * priority order. Null = unmatched (surface on the report, never
     * silently become stock).
     *
     * Deliberately matches INACTIVE types too: classification and
     * sellability are different things. E.g. 100G CWDM4 is used on core
     * links (same-rack) — it should classify cleanly for inventory, while
     * `active=false` keeps it out of sellable stock and the order form.
     */
    public static function matchXcvr( string $xcvr ): ?self
    {
        foreach( self::orderBy( 'priority' )->orderBy( 'id' )->get() as $pt ) {
            if( $pt->matches( $xcvr ) ) {
                return $pt;
            }
        }
        return null;
    }

    /**
     * Speed as a human label, e.g. 10000 → "10G".
     */
    public function speedLabel(): string
    {
        return $this->speed >= 1000
            ? rtrim( rtrim( number_format( $this->speed / 1000, 1 ), '0' ), '.' ) . 'G'
            : $this->speed . 'M';
    }
}
