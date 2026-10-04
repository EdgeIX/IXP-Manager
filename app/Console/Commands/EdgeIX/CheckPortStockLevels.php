<?php

namespace IXP\Console\Commands\EdgeIX;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use IXP\Models\PortOrder;
use IXP\Services\EdgeIX\PortStockService;

/**
 * EdgeIX ordering Phase 3: daily stock digest.
 *
 * Zero-touch ordering consumes prewired stock unattended, so this alert is
 * what keeps the pipeline fed. HTML email with three sections:
 *
 *  - LOW STOCK: per (location × type) below threshold, sorted by site,
 *    zero-sellable highlighted, "none installed yet" labelled for offered
 *    sites with no ports of the type at all.
 *  - OPEN BACKORDERS: accepted orders waiting on capacity — the other
 *    thing needing admin action daily.
 *  - UNMATCHED OPTICS: detected but unclassified SKUs.
 *
 * Nothing to report = no email. Scheduled daily 09:00 (Kernel), gated on
 * PORT_STOCK_ALERT_EMAIL.
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

        $rows = $stock->rows();

        $shortfalls = collect( $stock->shortfalls( $rows ) )
            ->sortBy( fn( $s ) => sprintf( '%s|%s', $s->location?->name ?? '~', $s->type->name ) )->values();

        $unmatched = $rows->where( 'unmatchedOptic', true )
            ->sortBy( fn( $r ) => $r->sp->switcher?->name . $r->sp->ifName )->values();

        $backorders = PortOrder::where( 'state', PortOrder::STATE_BACKORDER )
            ->with( [ 'customer', 'portType', 'location' ] )->orderBy( 'id' )->get();

        if( $shortfalls->isEmpty() && $unmatched->isEmpty() && $backorders->isEmpty() ) {
            $this->info( 'All port-stock levels at or above thresholds; no unmatched optics; no backorders.' );
            return self::SUCCESS;
        }

        $this->warn( sprintf( '%d low-stock, %d backorder(s), %d unmatched optic(s) — emailing %s',
            $shortfalls->count(), $backorders->count(), $unmatched->count(), $to ) );

        Log::warning( '[PortStock] Daily digest', [
            'shortfalls' => $shortfalls->map( fn( $s ) =>
                ( $s->location?->name ?? 'unknown' ) . '/' . $s->type->name . ': ' . $s->sellable . '<' . $s->threshold )->all(),
            'backorders' => $backorders->pluck( 'id' )->all(),
            'unmatched'  => $unmatched->map( fn( $r ) => $r->sp->switcher?->name . ':' . $r->sp->ifName )->all(),
        ] );

        $html    = $this->buildHtml( $shortfalls, $backorders, $unmatched );
        $subject = sprintf( '[IXP-Manager] Port stock: %d low-stock%s',
            $shortfalls->count(),
            $backorders->count() ? ', ' . $backorders->count() . ' backorder(s) waiting' : '' );

        try {
            Mail::html( $html, function ( $m ) use ( $to, $subject ) {
                $m->to( $to )->subject( $subject );
            } );
            $this->info( 'Digest email sent.' );
        } catch( \Throwable $e ) {
            $this->error( 'Failed to send email: ' . $e->getMessage() );
            Log::error( '[PortStock] Failed to send digest email', [ 'error' => $e->getMessage() ] );
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildHtml( $shortfalls, $backorders, $unmatched ): string
    {
        $td  = 'padding:4px 10px;border:1px solid #d1d5db;font-size:13px;';
        $th  = $td . 'background:#1f2937;color:#fff;text-align:left;';
        $tbl = 'border-collapse:collapse;margin:6px 0 18px 0;';
        $h   = 'font-family:sans-serif;';

        $html = "<div style=\"{$h}\">";
        $html .= '<h2 style="margin:0 0 4px 0;">EdgeIX port stock — daily check</h2>';
        $html .= '<p style="margin:0 0 14px 0;color:#6b7280;font-size:13px;">'
            . e( now()->format( 'D j M Y H:i' ) ) . ' — '
            . '<a href="' . e( url( '/admin/port-stock' ) ) . '">Port Stock</a> · '
            . '<a href="' . e( url( '/admin/port-order/list' ) ) . '">Order Queue</a></p>';

        if( $shortfalls->isNotEmpty() ) {
            $html .= '<h3 style="margin:0;">Low stock (' . $shortfalls->count() . ') — prewire more</h3>';
            $html .= "<table style=\"{$tbl}\"><tr>"
                . "<th style=\"{$th}\">Location</th><th style=\"{$th}\">Port type</th>"
                . "<th style=\"{$th}\">Sellable</th><th style=\"{$th}\">Threshold</th>"
                . "<th style=\"{$th}\">Ports of type at site</th></tr>";

            foreach( $shortfalls as $s ) {
                $sellable = $s->sellable === 0
                    ? '<strong style="color:#b91c1c;">0</strong>'
                    : (int)$s->sellable;
                $atSite = $s->total === 0
                    ? '<em style="color:#b45309;">none installed yet</em>'
                    : (int)$s->total;

                $html .= '<tr>'
                    . "<td style=\"{$td}\">" . e( $s->location?->name ?? 'unknown' ) . '</td>'
                    . "<td style=\"{$td}\">" . e( $s->type->name ) . '</td>'
                    . "<td style=\"{$td};text-align:center;\">{$sellable}</td>"
                    . "<td style=\"{$td};text-align:center;\">" . (int)$s->threshold . '</td>'
                    . "<td style=\"{$td};text-align:center;\">{$atSite}</td>"
                    . '</tr>';
            }
            $html .= '</table>';
        }

        if( $backorders->isNotEmpty() ) {
            $html .= '<h3 style="margin:0;">Open backorders (' . $backorders->count() . ') — capacity needed</h3>';
            $html .= "<table style=\"{$tbl}\"><tr>"
                . "<th style=\"{$th}\">Order</th><th style=\"{$th}\">Customer</th>"
                . "<th style=\"{$th}\">Requested</th><th style=\"{$th}\">Location</th>"
                . "<th style=\"{$th}\">Placed</th></tr>";

            foreach( $backorders as $o ) {
                $html .= '<tr>'
                    . "<td style=\"{$td}\"><a href=\"" . e( url( '/admin/port-order/view/' . $o->id ) ) . '">#' . (int)$o->id . '</a></td>'
                    . "<td style=\"{$td}\">" . e( $o->customer?->abbreviatedName ?? $o->customer?->name ?? '?' ) . '</td>'
                    . "<td style=\"{$td}\">" . (int)$o->quantity . ' × ' . e( $o->portType?->name ?? '?' ) . '</td>'
                    . "<td style=\"{$td}\">" . e( $o->location?->name ?? '?' ) . '</td>'
                    . "<td style=\"{$td}\">" . e( $o->created_at?->format( 'j M Y' ) ) . '</td>'
                    . '</tr>';
            }
            $html .= '</table>';
        }

        if( $unmatched->isNotEmpty() ) {
            $html .= '<h3 style="margin:0;">Unmatched optics (' . $unmatched->count() . ') — add a pattern in Port Types</h3>';
            $html .= "<table style=\"{$tbl}\"><tr>"
                . "<th style=\"{$th}\">Switch</th><th style=\"{$th}\">Port</th><th style=\"{$th}\">Detected</th></tr>";

            foreach( $unmatched as $r ) {
                $html .= '<tr>'
                    . "<td style=\"{$td}\">" . e( $r->sp->switcher?->name ?? '?' ) . '</td>'
                    . "<td style=\"{$td}\">" . e( $r->sp->ifName ) . '</td>'
                    . "<td style=\"{$td}\"><code>" . e( $r->sp->detected_xcvr ) . '</code></td>'
                    . '</tr>';
            }
            $html .= '</table>';
        }

        $html .= '<p style="color:#6b7280;font-size:12px;">Billing is manual — action new orders per trial/contract terms. '
            . 'Thresholds are per port type (evaluated per DC): Port Types → edit.</p>';
        $html .= '</div>';

        return $html;
    }
}
