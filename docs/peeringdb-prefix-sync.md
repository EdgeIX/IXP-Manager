# PeeringDB Prefix Limit Sync

Syncs global prefix limits (`maxprefixes` / `maxprefixesv6`) from PeeringDB into IXP-Manager. Keeps route server prefix limits aligned with what peers advertise in PeeringDB without manual data entry.

## How it works

PeeringDB publishes `info_prefixes4` and `info_prefixes6` per network. This feature pulls those values and updates the corresponding customer fields in IXP-Manager.

**Data model:**

| Field | Scope | Updated by sync? |
|-------|-------|-----------------|
| `Customer.maxprefixes` | Global IPv4 limit | Yes |
| `Customer.maxprefixesv6` | Global IPv6 limit | Yes |
| `VlanInterface.ipv4maxbgpprefix` | Per-VLAN IPv4 override | No — admin-managed |
| `VlanInterface.ipv6maxbgpprefix` | Per-VLAN IPv6 override | No — admin-managed |

Per-VlanInterface limits are never touched — they serve as admin overrides when a customer's limit at a specific exchange point differs from their global PeeringDB value.

## Artisan command (bulk sync)

```bash
php artisan ixp-manager:sync-peeringdb-prefixes
```

Fetches all PeeringDB networks in a single API call and updates every customer whose ASN is registered in PeeringDB (`in_peeringdb = true`).

### Options

| Option | Description |
|--------|------------|
| `--dry-run` | Show what would change without saving |
| `--no-override` | Skip customers that already have non-zero prefix limits set |
| `--multiplier=1.2` | Multiply PeeringDB values by this factor (e.g. 20% buffer) |

### Examples

```bash
# Preview changes
php artisan ixp-manager:sync-peeringdb-prefixes --dry-run

# Sync with a 20% buffer, only fill in missing values
php artisan ixp-manager:sync-peeringdb-prefixes --multiplier=1.2 --no-override

# Full sync, overwrite all
php artisan ixp-manager:sync-peeringdb-prefixes
```

### Scheduling

To run daily, add to `app/Console/Kernel.php` after the `update-in-peeringdb` command:

```php
$schedule->command('ixp-manager:sync-peeringdb-prefixes')->daily()
    ->skip( function() { return env( 'TASK_SCHEDULER_SKIP_SYNC_PEERINGDB_PREFIXES', false ); } );
```

Set `TASK_SCHEDULER_SKIP_SYNC_PEERINGDB_PREFIXES=true` in `.env` to disable without removing the schedule.

## Web UI — admin sync button

On the **admin customer overview** tab, a sync button appears next to the PeeringDB link (when the customer has `in_peeringdb = true`). Clicking it syncs that single customer's prefix limits immediately.

- **Route:** `POST /admin/customer/{id}/peeringdb/sync-prefixes`
- **Controller:** `PeeringDbSyncController@syncCustomer`
- **Auth:** superuser only (`web-auth-superuser.php`)
- **Skin:** `resources/skins/edgeix/customer/overview-tabs/overview.foil.php`

## Web UI — customer self-service button

On the **customer dashboard** (Details tab), a "Sync from PeeringDB" button lets customers update their own prefix limits. The button shows current IPv4/IPv6 values and updates them in-place after sync.

- **Route:** `POST /dashboard/peeringdb/sync-prefixes`
- **Controller:** `PeeringDbSyncController@syncOwn`
- **Auth:** any authenticated user (`web-auth.php`)
- **Skin:** `resources/skins/edgeix/dashboard/dashboard-tabs/details.foil.php`

## API response format

Both web endpoints return JSON:

```json
{
    "success": true,
    "changed": {
        "IPv4": { "from": 100, "to": 250 },
        "IPv6": { "from": 50, "to": 120 }
    },
    "v4": 250,
    "v6": 120
}
```

If PeeringDB has no prefix data:

```json
{
    "warning": "PeeringDB has no prefix limits set for AS64496. No changes made.",
    "v4": 100,
    "v6": 50
}
```

## Logging

All sync operations are logged to `laravel.log`:

- `PeeringDB prefix sync initiated by customer user` — customer self-service
- `PeeringDB prefix sync initiated by admin` — admin button
- `PeeringDB prefix sync: updated` — values changed (includes before/after)
- `PeeringDB prefix sync: already up to date` — no changes needed
- `PeeringDB prefix sync failed: no ASN` — customer has no ASN configured
- `PeeringDB prefix sync failed: API error` — PeeringDB API unreachable

## Files

| File | Purpose |
|------|---------|
| `app/Console/Commands/SyncPeeringDbPrefixes.php` | Artisan command (bulk sync) |
| `app/Http/Controllers/PeeringDbSyncController.php` | Web endpoints (admin + customer) |
| `routes/web-auth.php` | Customer self-service route |
| `routes/web-auth-superuser.php` | Admin sync route |
| `resources/skins/edgeix/customer/overview-tabs/overview.foil.php` | Admin button UI |
| `resources/skins/edgeix/dashboard/dashboard-tabs/details.foil.php` | Customer button UI |
