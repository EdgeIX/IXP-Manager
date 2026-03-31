# Service Reference IDs

Human-readable reference IDs for physical port connections, designed for support tickets, billing integration, and customer-facing identification.

## Format

```
{PREFIX}-{SHORT_CODE}-{PADDED_ID}
```

Example: `EIX-SYD-00475`

| Component | Source | Example |
|-----------|--------|---------|
| Prefix | `SERVICE_REF_PREFIX` env var (default: `EIX`) | `EIX` |
| Short Code | `infrastructure.short_code` field | `SYD` |
| Padded ID | VirtualInterface ID, zero-padded to 5 digits | `00475` |

## Configuration

Add to `.env` (optional — defaults to `EIX`):

```dotenv
SERVICE_REF_PREFIX=EIX
```

Config key: `ixp.service_ref_prefix`

## Infrastructure Short Codes

Each infrastructure requires a **Short Code** — a short alphabetic location identifier (e.g. `SYD`, `ADL`, `AKL`, `PER`).

Set via: **Infrastructures > Edit > Short Code** field.

The short code is used in:
- Service reference generation for VirtualInterfaces
- Infrastructure list view (Short Code column)

Short codes must be set **before** running the migration that backfills service references for existing VIs.

## How It Works

### Auto-generation

Service references are auto-generated when a VirtualInterface is created:

1. The VI is saved to the database (gets an ID)
2. The `booted()` hook on `VirtualInterface` fires
3. It looks up the infrastructure via the first physical interface's switch
4. If the infrastructure has a `short_code`, it generates: `{PREFIX}-{SHORT_CODE}-{PADDED_ID}`
5. The reference is saved to the `service_reference` column

If the infrastructure has no short code, no reference is generated.

### Admin-editable

The `service_reference` column is a plain string field. Admins can override the auto-generated value to match external billing systems (e.g. Xero invoice references).

### Uniqueness

The column has a unique constraint — no two VIs can have the same service reference.

## Where It Appears

| Location | View File | Visibility |
|----------|-----------|------------|
| Customer Ports tab | `skins/edgeix/customer/overview-tabs/ports/port.foil.php` | Admin + Customer |
| Admin customer overview (Ports) | Same file (shared template) | Admin |
| Pseudowire circuit detail (A-End) | `ixpm-pseudowire/views/admin/circuit/view.foil.php` | Admin |
| Pseudowire circuit detail (Z-End) | Same file | Admin |
| Pseudowire customer dashboard | `ixpm-pseudowire/views/customer/dashboard/index.foil.php` | Customer |

The reference is displayed as a badge on port connection headers and as a text label under port names in pseudowire views.

## Database Schema

### `infrastructure` table

```sql
ALTER TABLE infrastructure ADD COLUMN short_code VARCHAR(10) NULL AFTER shortname;
```

Migration: `2026_03_31_000001_add_short_code_to_infrastructure.php`

### `virtualinterface` table

```sql
ALTER TABLE virtualinterface ADD COLUMN service_reference VARCHAR(30) NULL UNIQUE AFTER description;
```

Migration: `2026_03_31_000002_add_service_reference_to_virtualinterface.php`

The second migration also backfills existing VIs using the infrastructure short codes (must be populated first).

## Deployment

1. Deploy code to server
2. Run `php artisan migrate` — creates both columns
3. Restart Apache (`systemctl restart apache2`)
4. Edit each infrastructure in admin UI — set the Short Code (e.g. `SYD`, `ADL`, `AKL`, `PER`)
5. Backfill existing VIs:

```bash
php artisan tinker --execute="
\$prefix = config('ixp.service_ref_prefix', 'EIX');
\$count = 0;
foreach (IXP\Models\VirtualInterface::whereNull('service_reference')->get() as \$vi) {
    \$ref = \$vi->generateServiceReference();
    if (\$ref) {
        \$vi->service_reference = \$ref;
        \$vi->saveQuietly();
        \$count++;
    }
}
echo \"Backfilled \$count VIs\";
"
```

New VIs will auto-generate service references on creation.

## Billing Integration

Service references provide a stable, human-readable identifier for each port connection that can be used as line-item references in billing systems like Xero. Unlike ASNs (which don't apply to non-peering services), service references are per-port and service-agnostic.

Pseudowire circuits have their own service IDs (`CUST-PW00034`, `CUST-PWN00045`, `CUST-PWI00056`) which serve the same purpose for cross-connect services.

## Files

| File | Purpose |
|------|---------|
| `database/migrations/2026_03_31_000001_add_short_code_to_infrastructure.php` | Adds `short_code` to infrastructure |
| `database/migrations/2026_03_31_000002_add_service_reference_to_virtualinterface.php` | Adds `service_reference` to virtualinterface + backfill |
| `app/Models/Infrastructure.php` | `short_code` in `$fillable` |
| `app/Models/VirtualInterface.php` | `service_reference` in `$fillable`, `booted()` auto-generation, `generateServiceReference()` |
| `app/Http/Controllers/InfrastructureController.php` | `short_code` validation, list column, edit form populate |
| `config/ixp.php` | `service_ref_prefix` config key |
| `resources/skins/edgeix/infrastructure/edit-form.foil.php` | Short Code input field |
| `resources/skins/edgeix/customer/overview-tabs/ports/port.foil.php` | Service reference badge display |
