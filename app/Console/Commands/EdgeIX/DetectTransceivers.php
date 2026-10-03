<?php

namespace IXP\Console\Commands\EdgeIX;

use IXP\Console\Commands\Command;

use OSS_SNMP\{
    Exception,
    SNMP
};

use IXP\Models\Switcher;
use IXP\Services\EdgeIX\TransceiverDetector;

/**
 * EdgeIX ordering Phase 3: detect transceivers via ENTITY-MIB and map them
 * to the port-type catalogue. Companion to switch:snmp-poll (same switch
 * selection, credentials and session handling) — kept as a separate EdgeIX
 * command so the upstream poller stays untouched.
 *
 * Intended to run from cron alongside the regular SNMP poll. First-time
 * fleet validation: run per platform with --nosave --debug and eyeball the
 * raw entity rows.
 *
 * See docs/ordering.md.
 */
class DetectTransceivers extends Command
{
    protected $signature = 'switch:detect-transceivers
                        {switch? : The name of the switch; loops over all active+polled switches if omitted}
                        {--nosave : Walk and report only — no database changes}
                        {--debug : Dump the raw ENTITY-MIB rows for each switch}';

    protected $description = 'EdgeIX: detect switch port transceivers via SNMP ENTITY-MIB and map to the sellable port-type catalogue';

    public function handle(): int
    {
        if( $this->argument( 'switch' ) ) {
            $switches = Switcher::where( 'name', $this->argument( 'switch' ) )->get();
            if( $switches->isEmpty() ) {
                $this->error( "ERR: No switch found with name: " . $this->argument( 'switch' ) );
                return -1;
            }
        } else {
            $switches = Switcher::where( 'active', true )->where( 'poll', true )->get();
        }

        $detector = new TransceiverDetector();
        $exit     = 0;

        foreach( $switches as $s ) {
            if( !$s->snmppasswd || trim( $s->snmppasswd ) === '' ) {
                if( !$this->isVerbosityQuiet() ) {
                    $this->info( "Skipping {$s->name} as no SNMP password set" );
                }
                continue;
            }

            if( !$this->isVerbosityQuiet() ) {
                $this->info( "Walking ENTITY-MIB on {$s->name} ({$s->hostname})" );
            }

            try {
                $host    = new SNMP( $s->hostname, $s->snmppasswd );
                $results = $detector->detect( $s, $host );
            } catch( Exception $e ) {
                $this->error( "ERROR: OSS_SNMP exception walking ENTITY-MIB on {$s->name}" );
                $exit = -1;
                continue;
            }

            if( $this->option( 'debug' ) ) {
                $this->table(
                    [ 'entIdx', 'class', 'name', 'alias', 'model', 'descr', 'containedIn' ],
                    collect( $detector->entities )->map( fn( $e, $idx ) => [
                        $idx, $e['class'], $e['name'], $e['alias'], $e['model'],
                        mb_substr( $e['descr'], 0, 48 ), $e['containedIn'],
                    ] )->all()
                );
            }

            $rows      = [];
            $unmatched = 0;

            foreach( $results['detected'] as $d ) {
                $rows[] = [
                    $d['port']->ifName,
                    $d['xcvr'],
                    $d['type']?->name ?? '** NO CATALOGUE MATCH **',
                ];
                if( !$d['type'] ) {
                    $unmatched++;
                }
            }

            if( !$this->isVerbosityQuiet() ) {
                $this->table( [ 'Port', 'Detected transceiver', 'Port type' ], $rows );

                foreach( $results['unmapped'] as $u ) {
                    $this->warn( "    Unmapped optic (no switch port found): entity {$u['entity']} [{$u['xcvr']}]"
                        . ( $u['iface'] ? " iface guess: {$u['iface']}" : ' (no iface name found)' ) );
                }
                if( $unmatched ) {
                    $this->warn( "    {$unmatched} detected optic(s) matched no active port type — add/adjust catalogue patterns." );
                }
            }

            if( $this->option( 'nosave' ) ) {
                $this->warn( '    *** --nosave set - NO CHANGES MADE TO DATABASE' );
                continue;
            }

            $detector->persist( $s, $results );
        }

        return $exit;
    }
}
