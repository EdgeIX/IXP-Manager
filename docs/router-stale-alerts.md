# Router Stale-Sync Alerts

Emails admins when a route server stops syncing or gets stuck on a config update lock. Without this, stuck routers go unnoticed until a customer reports stale routes.

## What it detects

The check looks at every router with `pause_updates = 0` and flags three classes of problem:

| Class | Condition | Default threshold |
|-------|-----------|-------------------|
| **Stuck** | `last_update_started` is set but no matching `last_updated` since, and the lock has been held for too long | 30 minutes |
| **Stale** | `last_updated` is older than the threshold | 24 hours |
| **Never** | `last_updated` has never been set | always alerts |

The "stuck" class catches the common failure mode where `api-reconfigure-birdv2.sh` crashes or is killed before calling `release-update-lock`, leaving the router unable to re-sync until an admin clears the lock manually.

## Setup

1. **Add the recipient to `.env`:**
   ```
   ROUTER_STALE_ALERT_EMAIL=ops@example.com
   ```

2. **Clear cached config** (if you have `php artisan config:cache` in your deploy):
   ```bash
   php artisan config:clear
   ```

The Laravel scheduler picks it up automatically — there's no extra cron needed, provided your `schedule:run` cron is already running every minute.

## Schedule

Runs hourly from `app/Console/Kernel.php`. Only fires if `ROUTER_STALE_ALERT_EMAIL` is set.

## Manual run

```bash
# Use defaults (24h stale, 30min stuck, recipient from config)
php artisan router:check-stale

# Tighter thresholds
php artisan router:check-stale --hours=12 --stuck-minutes=15

# Override the recipient (e.g. for testing)
php artisan router:check-stale --to=joe@example.com
```

## Sample alert email

```
IXP-Manager router sync health check
========================================

STUCK LOCKS (held > 30 minutes — last_update_started but no last_updated since):
  rs1-syd-ipv4 — locked 2 hours ago (at 2026-05-19 08:14:32)
  rs1-syd-ipv6 — locked 2 hours ago (at 2026-05-19 08:14:33)

To clear a stuck lock from tinker:
  IXP\Models\Router::where('handle','<handle>')->update(['last_update_started'=>null]);

STALE (last successful update > 24 hours ago):
  rs1-per-ipv4 — last updated 4 days ago (at 2026-05-15 10:11:00)

NEVER UPDATED (no last_updated timestamp ever):
  rs2-akl-ipv4

Admin URL: https://ixp.example.com/admin/router/list
```

## Clearing a stuck lock

Either from the email's tinker hint, or via SQL:

```sql
UPDATE router SET last_update_started = NULL WHERE handle = 'rs1-syd-ipv4';
```

After clearing the lock, the next scheduled `api-reconfigure-birdv2.sh` run on that router will succeed.

## Root cause investigation

If you see the same routers showing up as "stuck" repeatedly, the underlying sync script is failing mid-update. Common causes:

- BIRD reconfigure taking too long (large RIB or filter rebuild)
- SSH disconnect from cron host to router VM
- OOM kill on the router VM
- Network blip between cron host and IXP-Manager API

Check the cron host's syslog and the router VM's BIRD log around the timestamp in `last_update_started`.

## Disabling

Remove `ROUTER_STALE_ALERT_EMAIL` from `.env` (or set it empty) — the scheduler entry skips itself when the config is unset.

## Related files

- `app/Console/Commands/Router/CheckStaleRouters.php` — the command
- `app/Console/Kernel.php` — schedule registration
- `config/router.php` — config defaults
