<?php

namespace IXP\Console\Commands\EdgeIX;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

use IXP\Models\PortOrderPort;
use IXP\Models\Switcher;
use IXP\Models\SwitchPort;

/**
 * EdgeIX: prune switch port DB rows the switch no longer has.
 *
 * The core poller warns "port found in database with no matching port on
 * the switch" every run but upstream offers no bulk cleanup — stale rows
 * pollute stock, detection fan-out and sibling logic. This deletes rows
 * that are BOTH:
 *
 *   stale      — lastSnmpPoll missing or older than the switch's
 *                lastPolled minus 2h (the port wasn't seen by recent
 *                polls), OR a duplicate ifName row (the freshest / an
 *                attached one is kept);
 *   unattached — no physical interface (service), no patch panel port
 *                link, no port-order reservation.
 *
 * Attached-but-stale rows are REPORTED, never deleted — a stale row with
 * a service or panel link needs a human decision.
 *
 * DRY RUN by default; pass --delete to act.
 *
 *   php artisan switch:prune-stale-ports            # whole fleet, dry run
 *   php artisan switch:prune-stale-ports pe1akl1 --delete
 */
class PruneStaleSwitchPorts extends Command
{
    protected $signature = 'switch:prune-stale-ports
                            {switch? : Switch name; all active switches if omitted}
                            {--delete : Actually delete (default is dry run)}';

    protected $description = 'EdgeIX: delete stale/duplicate switch port DB rows that have no service, panel link or order attached';

    public function handle(): int
    {
        if( $this->argument( 'switch' ) ) {
            $switches = Switcher::where( 'name', $this->argument( 'switch' ) )->get();
            if( $switches->isEmpty() ) {
                $this->error( 'No switch named ' . $this->argument( 'switch' ) );
                return self::FAILURE;
            }
        } else {
            $switches = Switcher::where( 'active', true )->get();
        }

        $reservedIds = PortOrderPort::pluck( 'switchportid' )->flip();
        $dry         = !$this->option( 'delete' );
        $totalDel    = 0;

        foreach( $switches as $s ) {
            if( !$s->lastPolled ) {
                $this->warn( "{$s->name}: never SNMP-polled — skipping (no baseline to prune against)." );
                continue;
            }

            $cutoff = Carbon::parse( $s->lastPolled )->subHours( 2 );
            $ports  = $s->switchPorts()->with( [ 'physicalInterface', 'patchPanelPort' ] )->get();

            // Duplicate ifName rows: keep an attached one if any, else the
            // most recently polled; the rest become prune candidates.
            $dupIds = [];
            foreach( $ports->groupBy( 'ifName' ) as $group ) {
                if( $group->count() < 2 ) {
                    continue;
                }
                $keep = $group->sortByDesc( fn( SwitchPort $p ) =>
                    ( ( $p->physicalInterface || $p->patchPanelPort ) ? '1' : '0' ) . '|' . (string)$p->lastSnmpPoll )->first();
                foreach( $group as $p ) {
                    if( $p->id !== $keep->id ) {
                        $dupIds[ $p->id ] = true;
                    }
                }
            }

            $rows = [];
            foreach( $ports as $p ) {
                $isDup   = isset( $dupIds[ $p->id ] );
                $isStale = !$p->lastSnmpPoll || Carbon::parse( $p->lastSnmpPoll )->lt( $cutoff );

                if( !$isDup && !$isStale ) {
                    continue;
                }

                $attached = [];
                if( $p->physicalInterface )           { $attached[] = 'service'; }
                if( $p->patchPanelPort )              { $attached[] = 'panel link'; }
                if( isset( $reservedIds[ $p->id ] ) ) { $attached[] = 'order'; }

                $why = $isDup ? 'duplicate ifName' : 'not seen by recent polls';

                if( $attached ) {
                    $rows[] = [ $p->ifName, $p->id, $why, 'KEPT — attached: ' . implode( ', ', $attached ) ];
                    continue;
                }

                $rows[] = [ $p->ifName, $p->id, $why, $dry ? 'would delete' : 'DELETED' ];

                if( !$dry ) {
                    $p->delete();
                    $totalDel++;
                }
            }

            if( $rows ) {
                $this->info( $s->name . ':' );
                $this->table( [ 'Port', 'DB id', 'Reason', 'Action' ], $rows );
            } elseif( !$this->isVerbosityQuiet() ) {
                $this->line( "{$s->name}: clean." );
            }
        }

        if( $dry ) {
            $this->warn( 'DRY RUN — nothing deleted. Re-run with --delete to act.' );
        } else {
            Log::info( "[PruneStaleSwitchPorts] deleted {$totalDel} stale switch port row(s)" );
            $this->info( "{$totalDel} row(s) deleted." );
        }

        return self::SUCCESS;
    }

    private function isVerbosityQuiet(): bool
    {
        return $this->output->isQuiet();
    }
}
