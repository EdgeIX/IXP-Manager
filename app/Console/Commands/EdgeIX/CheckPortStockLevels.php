<?php

namespace IXP\Console\Commands\EdgeIX;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use IXP\Services\EdgeIX\PortStockService;

/**
 * EdgeIX ordering Phase 3: low-stock alerting for sellable prewired ports.
 *
 * Zero-touch ordering consumes prewired stock unattended, so this alert is
 * what keeps the pipeline fed — admins must prewire BEFORE orders get
 * blocked, not at zero. Per (location × port type) where the type has a
 * low_stock_threshold: alert when sellable < threshold. Locations that
 * don't deploy a type at all stay silent (no noise for types a site never
 * stocks). Also nags about detected optics with no catalogue match, so
 * unclassified SKUs can't silently hide from stock.
 *
 * Mirrors router:check-stale (plain-text digest email to an env-configured
 * admin address). Schedule daily from Kernel/cron:
 *
 *   php artisan port-stock:check-levels
 *   php artisan port-stock:check-levels --to=ops@example.com
 */
class CheckPortStockLevels extends Command
{
    protected $signature = 'port-stock:check-levels
                            {--to= : Override recipient (defaults to PORT_STOCK_ALERT_EMAIL / config porttype.low_stock_alert_email)}';

    protected $description = 'EdgeIX: email admins when sellable prewired port stock drops below per-type thresholds';

    public function handle( PortStockService $stock ): int
    {
        $to = $this->option( 'to' ) ?: config( 'porttype.low_stock_alert_email' );

        if( !$to ) {
            $this->error( 'No recipient configured. Set PORT_STOCK_ALERT_EMAIL in .env or pass --to=' );
            return self::FAILURE;
        }

        $rows       = $stock->rows();
        $shortfalls = $stock->shortfalls( $rows );
        $unmatched  = $rows->where( 'unmatchedOptic', true );

        if( !count( $shortfalls ) && $unmatched->isEmpty() ) {
            $this->info( 'All port-stock levels at or above thresholds; no unmatched optics.' );
            return self::SUCCESS;
        }

        $this->warn( sprintf( '%d low-stock shortfall(s), %d unmatched optic(s) — emailing %s',
            count( $shortfalls ), $unmatched->count(), $to ) );

        Log::warning( '[PortStock] Low stock / unmatched optics', [
            'shortfalls' => collect( $shortfalls )->map( fn( $s ) =>
                ( $s->location?->name ?? 'unknown' ) . '/' . $s->type->name . ': ' . $s->sellable . '<' . $s->threshold )->all(),
            'unmatched'  => $unmatched->map( fn( $r ) => $r->sp->switcher?->name . ':' . $r->sp->ifName )->all(),
        ] );

        $lines = [ 'EdgeIX sellable port stock check', str_repeat( '=', 40 ), '' ];

        if( count( $shortfalls ) ) {
            $lines[] = 'LOW STOCK (sellable prewired ports below threshold — prewire more):';
            foreach( $shortfalls as $s ) {
                $lines[] = sprintf( '  %-20s %-22s sellable: %d (threshold %d, %d port(s) of this type at site)',
                    $s->location?->name ?? 'unknown location', $s->type->name, $s->sellable, $s->threshold, $s->total );
            }
            $lines[] = '';
        }

        if( $unmatched->isNotEmpty() ) {
            $lines[] = 'UNMATCHED OPTICS (detected but no catalogue match — add a pattern in Port Types):';
            foreach( $unmatched as $r ) {
                $lines[] = sprintf( '  %s %s — %s', $r->sp->switcher?->name, $r->sp->ifName, $r->sp->detected_xcvr );
            }
            $lines[] = '';
        }

        $lines[] = 'Stock:      ' . url( '/admin/port-stock' );
        $lines[] = 'Port types: ' . url( '/admin/port-type/list' );

        $body  = implode( "\n", $lines );
        $count = count( $shortfalls );

        try {
            Mail::raw( $body, function ( $m ) use ( $to, $count ) {
                $m->to( $to )
                  ->subject( '[IXP-Manager] Port stock: ' . $count . ' low-stock shortfall(s)' );
            } );
            $this->info( 'Alert email sent.' );
        } catch( \Throwable $e ) {
            $this->error( 'Failed to send email: ' . $e->getMessage() );
            Log::error( '[PortStock] Failed to send low-stock alert email', [ 'error' => $e->getMessage() ] );
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
