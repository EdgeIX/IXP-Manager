<?php

namespace IXP\Services\EdgeIX;

use OSS_SNMP\SNMP;

use IXP\Models\PortType;
use IXP\Models\Switcher;
use IXP\Models\SwitchPort;

/**
 * EdgeIX ordering Phase 3: automatic transceiver detection via ENTITY-MIB.
 *
 * Walks entPhysicalModelName / Descr / Name / Alias / ContainedIn over the
 * same OSS_SNMP session the core poller uses. The ENTITY-MIB is populated
 * for inserted optics regardless of link state — unlike ifHighSpeed (only
 * truthful once negotiated) and the MAU MIB (sparsely implemented, no IANA
 * codes for many modern optics).
 *
 * Mapping an entity to a switch port: the interface name is extracted from
 * the entity's own name/alias/description, or failing that from its parent
 * entities (containedIn, up to 3 hops) — platforms differ on which field
 * carries "EthernetX". Breakouts (PSM4/PLR4 4x10G etc.): when the entity
 * names the parent cage ("Ethernet49") but the switch runs breakout legs
 * ("Ethernet49/1".."/4"), the optic is stamped onto every leg — each leg is
 * its own sellable stock item.
 *
 * The detected string is matched against the admin port-type catalogue
 * (PortType::matchXcvr) — unmatched optics are reported, never silently
 * turned into stock. See docs/ordering.md.
 */
class TransceiverDetector
{
    /**
     * Raw entity rows from the last walk — kept for --debug output.
     *
     * @var array<int, array{class: int|null, name: string, alias: string, model: string, descr: string, containedIn: int|null}>
     */
    public array $entities = [];

    /**
     * Walk the switch and return per-switchport detection results.
     *
     * @return array{
     *     detected: array<int, array{port: SwitchPort, xcvr: string, type: PortType|null}>,
     *     unmapped: array<int, array{entity: int, xcvr: string, iface: string|null}>,
     * }  'detected' is keyed by switchport id; 'unmapped' holds optics we
     *     couldn't tie to a port (data for the report, not an error).
     */
    public function detect( Switcher $switch, SNMP $host ): array
    {
        $entity = $host->useEntity();

        $models  = $entity->physicalModelName();
        $descrs  = $entity->physicalDescription();
        $names   = $entity->physicalName();
        $aliases = $entity->physicalAlias();
        $parents = $entity->physicalContainedIndex();
        $classes = $entity->physicalClass();

        $this->entities = [];
        foreach( $models as $idx => $model ) {
            $this->entities[ $idx ] = [
                'class'       => $classes[ $idx ] ?? null,
                'name'        => trim( (string)( $names[ $idx ] ?? '' ) ),
                'alias'       => trim( (string)( $aliases[ $idx ] ?? '' ) ),
                'model'       => trim( (string)$model ),
                'descr'       => trim( (string)( $descrs[ $idx ] ?? '' ) ),
                'containedIn' => isset( $parents[ $idx ] ) ? (int)$parents[ $idx ] : null,
            ];
        }

        // Switch ports indexed by ifName for mapping.
        $ports = $switch->switchPorts()->get()->keyBy( 'ifName' );

        $detected = [];
        $unmapped = [];

        foreach( $this->entities as $idx => $e ) {
            $xcvr = $e['model'] !== '' ? $e['model'] : $e['descr'];

            if( $xcvr === '' || !$this->looksLikeTransceiver( $e ) ) {
                continue;
            }

            $iface = $this->ifaceNameForEntity( $idx );

            $targets = $iface ? $this->portsForIface( $iface, $ports ) : collect();

            if( $targets->isEmpty() ) {
                $unmapped[] = [ 'entity' => $idx, 'xcvr' => $xcvr, 'iface' => $iface ];
                continue;
            }

            $matchString = trim( $e['model'] . ' ' . $e['descr'] );
            $type = PortType::matchXcvr( $matchString );

            foreach( $targets as $port ) {
                // Never let a parent-cage entity overwrite a more specific
                // per-leg detection that already landed this run.
                if( isset( $detected[ $port->id ] ) && $iface !== $port->ifName ) {
                    continue;
                }
                $detected[ $port->id ] = [ 'port' => $port, 'xcvr' => $xcvr, 'type' => $type ];
            }
        }

        return [ 'detected' => $detected, 'unmapped' => $unmapped ];
    }

    /**
     * Persist a detect() result set: stamp detections, clear stale ones.
     * Only call after a successful walk — a port absent from the results
     * then genuinely has no detectable optic. Shared by the artisan
     * command and the admin UI's "save to database".
     */
    public function persist( Switcher $switch, array $results ): void
    {
        $now = now();

        foreach( $results['detected'] as $d ) {
            $sp = $d['port'];
            $sp->detected_xcvr    = mb_substr( $d['xcvr'], 0, 128 );
            $sp->detected_xcvr_at = $now;
            $sp->port_type_id     = $d['type']?->id;
            $sp->save();
        }

        $detectedIds = array_keys( $results['detected'] );

        $switch->switchPorts()
            ->whereNotIn( 'id', $detectedIds ?: [ 0 ] )
            ->whereNotNull( 'detected_xcvr' )
            ->update( [ 'detected_xcvr' => null, 'detected_xcvr_at' => null, 'port_type_id' => null ] );
    }

    /**
     * Is this entity plausibly an optic/transceiver (vs PSU, fan, chassis)?
     */
    private function looksLikeTransceiver( array $e ): bool
    {
        $haystack = $e['model'] . ' ' . $e['descr'] . ' ' . $e['name'];

        if( preg_match( '/xcvr|transceiver|sfp|qsfp|osfp|cfp|[0-9]+G(BASE)?-/i', $haystack ) ) {
            // ...but not obvious non-optics that mention module names.
            return !preg_match( '/power supply|fan|sensor|chassis|supervisor/i', $haystack );
        }

        return false;
    }

    /**
     * Pull an interface name out of the entity's own fields, or walk up the
     * containment tree (platforms differ on where "EthernetX" lives).
     */
    private function ifaceNameForEntity( int $idx, int $depth = 0 ): ?string
    {
        if( $depth > 3 || !isset( $this->entities[ $idx ] ) ) {
            return null;
        }

        $e = $this->entities[ $idx ];

        foreach( [ $e['name'], $e['alias'], $e['descr'] ] as $field ) {
            if( preg_match( '/\b(Ethernet[0-9]+(?:\/[0-9]+)*(?:\.[0-9]+)?)\b/i', $field, $m ) ) {
                return $m[1];
            }
        }

        if( $e['containedIn'] !== null && $e['containedIn'] !== $idx ) {
            return $this->ifaceNameForEntity( $e['containedIn'], $depth + 1 );
        }

        return null;
    }

    /**
     * Resolve an interface name to switch port(s). Exact ifName match wins;
     * a parent-cage name with no exact port fans out to its breakout legs
     * (Ethernet49 → Ethernet49/1../4).
     *
     * @param \Illuminate\Support\Collection<string, SwitchPort> $ports keyed by ifName
     */
    private function portsForIface( string $iface, $ports )
    {
        if( $exact = $ports->get( $iface ) ) {
            return collect( [ $exact ] );
        }

        return $ports->filter(
            fn( SwitchPort $p ) => str_starts_with( (string)$p->ifName, $iface . '/' )
        )->values();
    }
}
