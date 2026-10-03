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
     * SNMP-only by design: IXP-Manager has READ-ONLY SNMP access to the
     * switches and deliberately no API (eAPI/gNMI) credentials — any
     * richer switch access belongs to the config agent, not here.
     * Classification order: ENTITY model/descr → stored mauType, both
     * through the same catalogue. Gaps the two MIBs can't name (e.g.
     * optics EOS itself reports as UnknownOptical400G) are handled by
     * vendor-P/N catalogue patterns or the per-port admin override.
     *
     * @return array{
     *     detected: array<int, array{port: SwitchPort, xcvr: string, mau: string|null, type: PortType|null, source: string}>,
     *     unmapped: array<int, array{entity: int, xcvr: string, iface: string|null}>,
     * }  'detected' is keyed by switchport id; 'unmapped' holds optics we
     *     couldn't tie to a port (data for the report, not an error).
     */
    public function detect( Switcher $switch, SNMP $host ): array
    {
        $entity = $host->useEntity();

        $models   = $entity->physicalModelName();
        $descrs   = $entity->physicalDescription();
        $names    = $entity->physicalName();
        $aliases  = $entity->physicalAlias();
        $parents  = $entity->physicalContainedIndex();
        $classes  = $entity->physicalClass();
        $vendors  = $entity->physicalVendorType();

        $this->entities = [];
        foreach( $models as $idx => $model ) {
            $this->entities[ $idx ] = [
                'class'       => $classes[ $idx ] ?? null,
                'name'        => trim( (string)( $names[ $idx ] ?? '' ) ),
                'alias'       => trim( (string)( $aliases[ $idx ] ?? '' ) ),
                'model'       => trim( (string)$model ),
                'descr'       => trim( (string)( $descrs[ $idx ] ?? '' ) ),
                'vendorType'  => trim( (string)( $vendors[ $idx ] ?? '' ) ),
                'containedIn' => isset( $parents[ $idx ] ) ? (int)$parents[ $idx ] : null,
            ];
        }

        // PHYSICAL switch ports only, indexed by ifName. Sub-interfaces
        // (Ethernet1/2.190 — dot1q units) are logical and never carry an
        // optic; without this filter a cage entity fans out onto them.
        //
        // Also exclude STALE rows: ports in the DB with no matching port on
        // the switch (the core poller warns about these every run). Their
        // lastSnmpPoll froze when the port disappeared, so anything clearly
        // older than the switch's lastPolled is a ghost — otherwise a cage
        // entity ("Ethernet12") fans its optic onto DB-only legs
        // (Ethernet12/2-4) that don't exist on the switch. Null timestamps
        // are kept (benefit of the doubt). Deleting the stale rows is still
        // the real fix — they pollute stock counts too.
        $staleCutoff = $switch->lastPolled
            ? \Carbon\Carbon::parse( $switch->lastPolled )->subHours( 2 )
            : null;

        $ports = $switch->switchPorts()->get()
            ->filter( fn( SwitchPort $p ) => $p->ifName && !str_contains( $p->ifName, '.' ) )
            ->filter( fn( SwitchPort $p ) => !$staleCutoff || !$p->lastSnmpPoll
                || \Carbon\Carbon::parse( $p->lastSnmpPoll )->gte( $staleCutoff ) )
            ->keyBy( 'ifName' );

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
                // Chassis (class 3) and slot containers (class 5) routinely
                // mention QSFP/Xcvr in their strings without being optics —
                // e.g. "DCS-7280QR-C36" or "Xcvr Slot 1". Don't report them
                // as unmapped; real optics live on module/port entities.
                if( !in_array( (int)( $e['class'] ?? 0 ), [ 3, 5 ], true ) ) {
                    $unmapped[] = [ 'entity' => $idx, 'xcvr' => $xcvr, 'iface' => $iface ];
                }
                continue;
            }

            $matchString = trim( $e['model'] . ' ' . $e['descr'] );

            foreach( $targets as $port ) {
                // Never let a parent-cage entity overwrite a more specific
                // per-leg detection that already landed this run.
                if( isset( $detected[ $port->id ] ) && $iface !== $port->ifName ) {
                    continue;
                }
                // Type is per PORT, not per optic: the port's configured
                // speed (ifHighSpeed) disambiguates breakout legs vs a
                // straight run of the same optic family.
                $type = PortType::matchXcvr( $matchString, (int)$port->ifHighSpeed ?: null );
                $detected[ $port->id ] = [ 'port' => $port, 'xcvr' => $xcvr, 'mau' => null, 'type' => $type, 'source' => 'entity' ];
            }
        }

        // Second source: the MAU MIB (ifMauType). Third-party optics usually
        // report their vendor PART NUMBER in entPhysicalModelName (e.g.
        // "Q.1340G.10") — useless for classification — while ifMauType
        // carries the actual media type ("40GbasePLR4", "100GbaseDR"…).
        //
        // We walk it RAW here and translate with our own table rather than
        // using the core poller's stored switchport.mauType: OSS_SNMP's
        // bundled translation stops at ~2014 (nothing past 100GbaseLR4), so
        // modern IANA values (100GbaseDR=126, FR1/LR1, the 400G set — the
        // registry is actively maintained, last updated 2026-07) come back
        // as "*** UNKNOWN ***" in the stored column. Stored mauType remains
        // the fallback when the live walk yields nothing for a port.
        //
        // When the ENTITY strings didn't match the catalogue, the MAU string
        // goes through the same catalogue; when ENTITY found nothing at all,
        // MAU alone establishes the detection. The ENTITY part number is
        // kept as the displayed/stored optic string either way.
        $mauByIfIndex = $this->walkMauTypes( $host );

        foreach( $ports as $port ) {
            $mau = $mauByIfIndex[ $port->ifIndex ] ?? trim( (string)$port->mauType );
            if( $mau === '' || $mau === '(empty)' || strtolower( $mau ) === 'unknown' || $mau === '*** UNKNOWN ***' ) {
                continue;
            }

            $portSpeed = (int)$port->ifHighSpeed ?: null;

            if( isset( $detected[ $port->id ] ) ) {
                $detected[ $port->id ]['mau'] = $mau;
                if( !$detected[ $port->id ]['type'] && ( $mauType = PortType::matchXcvr( $mau, $portSpeed ) ) ) {
                    $detected[ $port->id ]['type']   = $mauType;
                    $detected[ $port->id ]['source'] = 'entity+mau';
                }
            } else {
                $detected[ $port->id ] = [
                    'port'   => $port,
                    'xcvr'   => $mau,
                    'mau'    => $mau,
                    'type'   => PortType::matchXcvr( $mau, $portSpeed ),
                    'source' => 'mau',
                ];
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
     * IANA MAU types OSS_SNMP's bundled table predates (it stops at
     * 100GbaseLR4 = .77 plus some Arista private OIDs). Values from the
     * live IANA-MAU-MIB registry (iana.org/assignments/ianamau-mib,
     * revision 2026-07-15). Fiber/optic types only — that's what ports
     * stock is made of. Extend here as IEEE mints new PHYs.
     */
    private const IANA_MAU_EXTRA = [
        '.1.3.6.1.2.1.26.4.93'  => '25GbaseSR',
        '.1.3.6.1.2.1.26.4.114' => '25GbaseLR',
        '.1.3.6.1.2.1.26.4.115' => '25GbaseER',
        '.1.3.6.1.2.1.26.4.95'  => '40GbaseER4',
        '.1.3.6.1.2.1.26.4.101' => '100GbaseR',
        '.1.3.6.1.2.1.26.4.102' => '100GbaseSR4',
        '.1.3.6.1.2.1.26.4.125' => '100GbaseSR2',
        '.1.3.6.1.2.1.26.4.126' => '100GbaseDR',
        '.1.3.6.1.2.1.26.4.145' => '100GbaseFR1',
        '.1.3.6.1.2.1.26.4.146' => '100GbaseLR1',
        '.1.3.6.1.2.1.26.4.194' => '100GbaseZR',
        '.1.3.6.1.2.1.26.4.231' => '100GbaseSR1',
        '.1.3.6.1.2.1.26.4.127' => '200GbaseR',
        '.1.3.6.1.2.1.26.4.128' => '200GbaseDR4',
        '.1.3.6.1.2.1.26.4.129' => '200GbaseFR4',
        '.1.3.6.1.2.1.26.4.130' => '200GbaseLR4',
        '.1.3.6.1.2.1.26.4.135' => '400GbaseR',
        '.1.3.6.1.2.1.26.4.136' => '400GbaseSR16',
        '.1.3.6.1.2.1.26.4.137' => '400GbaseDR4',
        '.1.3.6.1.2.1.26.4.138' => '400GbaseFR8',
        '.1.3.6.1.2.1.26.4.139' => '400GbaseLR8',
        '.1.3.6.1.2.1.26.4.140' => '400GbaseER8',
        '.1.3.6.1.2.1.26.4.147' => '400GbaseFR4',
        '.1.3.6.1.2.1.26.4.148' => '400GbaseLR46',
        '.1.3.6.1.2.1.26.4.149' => '400GbaseSR8',
        '.1.3.6.1.2.1.26.4.150' => '400GbaseSR4p2',
        '.1.3.6.1.2.1.26.4.219' => '400GbaseDR42',
    ];

    /**
     * Live ifMauType walk, keyed by ifIndex, translated through OSS_SNMP's
     * table merged with IANA_MAU_EXTRA. An OID neither table knows is kept
     * RAW (never "*** UNKNOWN ***") — visible to admins, and a catalogue
     * pattern can match the OID string itself as a last resort. Returns []
     * when the switch doesn't answer the MAU MIB.
     *
     * @return array<int|string, string>
     */
    private function walkMauTypes( SNMP $host ): array
    {
        try {
            $raw = $host->useMAU()->types( false );
        } catch( \Exception $e ) {
            return [];
        }

        $map = self::IANA_MAU_EXTRA + \OSS_SNMP\MIBS\MAU::$TYPES;

        $out = [];
        foreach( $raw as $ifIndex => $oid ) {
            $oid = trim( (string)$oid );
            // zeroDotZero / none = empty cage
            if( $oid === '' || $oid === '.0.0' || $oid === '0.0' || str_ends_with( $oid, '.26.4.1' ) || str_ends_with( $oid, '.26.4.2' ) ) {
                continue;
            }
            $out[ $ifIndex ] = $map[ $oid ] ?? $oid;
        }

        return $out;
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
            if( preg_match( '/\b(Ethernet[0-9]+(?:\/[0-9]+)*)\b/i', $field, $m ) ) {
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
     * a parent-cage name with no exact port fans out to its PHYSICAL
     * breakout legs only (Ethernet49 → Ethernet49/1../4 — a single
     * digits-only step, never dot1q sub-interfaces).
     *
     * @param \Illuminate\Support\Collection<string, SwitchPort> $ports keyed by ifName
     */
    private function portsForIface( string $iface, $ports )
    {
        if( $exact = $ports->get( $iface ) ) {
            return collect( [ $exact ] );
        }

        $legRegex = '/^' . preg_quote( $iface, '/' ) . '\/\d+$/i';

        return $ports->filter(
            fn( SwitchPort $p ) => preg_match( $legRegex, (string)$p->ifName )
        )->values();
    }
}
