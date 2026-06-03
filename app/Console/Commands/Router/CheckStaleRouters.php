<?php

namespace IXP\Console\Commands\Router;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use IXP\Models\Router;

/**
 * Detect routers whose last successful update is older than a threshold,
 * or have a stuck lock (last_update_started without a matching last_updated).
 *
 * Emails admins so they can investigate / reset the lock.
 *
 * Schedule from Kernel.php, e.g. hourly.
 *
 * Usage:
 *   php artisan router:check-stale
 *   php artisan router:check-stale --hours=24
 *   php artisan router:check-stale --hours=24 --to=ops@example.com
 */
class CheckStaleRouters extends Command
{
    protected $signature = 'router:check-stale
                            {--hours=24 : Alert if last_updated is older than this many hours}
                            {--stuck-minutes=30 : Alert if a lock (last_update_started) is held longer than this}
                            {--to= : Override recipient (defaults to config router.stale_alert_email)}';

    protected $description = 'Email admins when route servers stop updating or get stuck on a lock';

    public function handle(): int
    {
        $hours        = (int) $this->option('hours');
        $stuckMinutes = (int) $this->option('stuck-minutes');
        $to           = $this->option('to') ?: config('router.stale_alert_email');

        if (!$to) {
            $this->error('No recipient configured. Set ROUTER_STALE_ALERT_EMAIL in .env or pass --to=');
            return self::FAILURE;
        }

        $staleThreshold = Carbon::now()->subHours($hours);
        $stuckThreshold = Carbon::now()->subMinutes($stuckMinutes);

        $stale = [];
        $stuck = [];
        $never = [];

        foreach (Router::where('pause_updates', 0)->get() as $r) {
            // Stuck lock: started but never completed, and started long ago
            if ($r->last_update_started && (!$r->last_updated || $r->last_update_started->gt($r->last_updated))) {
                if ($r->last_update_started->lt($stuckThreshold)) {
                    $stuck[] = $r;
                    continue;
                }
            }

            // Never updated at all
            if (!$r->last_updated) {
                $never[] = $r;
                continue;
            }

            // Stale: last successful update too old
            if ($r->last_updated->lt($staleThreshold)) {
                $stale[] = $r;
            }
        }

        $total = count($stale) + count($stuck) + count($never);
        if ($total === 0) {
            $this->info('All routers updated within the last ' . $hours . ' hours.');
            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Found %d stale, %d stuck, %d never-updated routers — emailing %s',
            count($stale), count($stuck), count($never), $to
        ));

        Log::warning('[Router] Stale routers detected', [
            'stale_count'  => count($stale),
            'stuck_count'  => count($stuck),
            'never_count'  => count($never),
            'stale'        => collect($stale)->pluck('handle')->all(),
            'stuck'        => collect($stuck)->pluck('handle')->all(),
            'never'        => collect($never)->pluck('handle')->all(),
        ]);

        $body = $this->buildEmailBody($stale, $stuck, $never, $hours, $stuckMinutes);

        try {
            Mail::raw($body, function ($m) use ($to, $total) {
                $m->to($to)
                  ->subject('[IXP-Manager] ' . $total . ' router(s) stale or stuck');
            });
            $this->info('Alert email sent.');
        } catch (\Throwable $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
            Log::error('[Router] Failed to send stale alert email', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildEmailBody(array $stale, array $stuck, array $never, int $hours, int $stuckMinutes): string
    {
        $lines = ["IXP-Manager router sync health check", str_repeat('=', 40), ''];

        if (!empty($stuck)) {
            $lines[] = "STUCK LOCKS (held > {$stuckMinutes} minutes — last_update_started but no last_updated since):";
            foreach ($stuck as $r) {
                $age = $r->last_update_started->diffForHumans();
                $lines[] = sprintf('  %s — locked %s (at %s)',
                    $r->handle, $age, $r->last_update_started->toDateTimeString());
            }
            $lines[] = '';
            $lines[] = 'To clear a stuck lock from tinker:';
            $lines[] = '  IXP\\Models\\Router::where(\'handle\',\'<handle>\')->update([\'last_update_started\'=>null]);';
            $lines[] = '';
        }

        if (!empty($stale)) {
            $lines[] = "STALE (last successful update > {$hours} hours ago):";
            foreach ($stale as $r) {
                $lines[] = sprintf('  %s — last updated %s (at %s)',
                    $r->handle, $r->last_updated->diffForHumans(), $r->last_updated->toDateTimeString());
            }
            $lines[] = '';
        }

        if (!empty($never)) {
            $lines[] = 'NEVER UPDATED (no last_updated timestamp ever):';
            foreach ($never as $r) {
                $lines[] = '  ' . $r->handle;
            }
            $lines[] = '';
        }

        $lines[] = 'Admin URL: ' . url('/admin/router/list');

        return implode("\n", $lines);
    }
}
