# Akvorado Grapher Backend for IXP Manager

Drop-in replacement for the legacy Perl sflow-to-RRD pipeline. Uses the [Akvorado](https://github.com/akvorado/akvorado) flow collector's REST API to serve sFlow-based traffic graphs (P2P, per-customer, per-exchange) rendered with interactive uPlot charts instead of static PNG images.

**Replaces:** `jix-sflow-to-rrd-handler`, `jix-interface-map-dump`, `Sflow.php` backend, RRD files, `rrdcached`
**Retains:** `sflow-detect-ixp-bgp-sessions` (BGP bilateral detection — unchanged)

---

## Prerequisites

1. **Akvorado** deployed and receiving sFlow from your switches
2. **Akvorado schema** must include MAC and VLAN columns:

```yaml
# akvorado.yaml
schema:
  enabled:
    - SrcVlan
    - DstVlan
    - SrcMAC
    - DstMAC
```

3. **IXP-Manager** with configured MAC addresses (`l2address` table) for all peering members. The backend resolves traffic by MAC address — members without configured MACs will show no data.

4. **Network access** from IXP-Manager to Akvorado's HTTP API (default port 8080)

---

## Installation

### 1. Add Files

Copy these files into your IXP-Manager installation:

```
app/Services/Akvorado/AkvoradoService.php       # HTTP client + query builder
app/Services/Grapher/Backend/Akvorado.php        # Grapher backend integration
app/Console/Commands/Grapher/UploadDailyP2pAkvorado.php  # Daily P2P stats collector
```

### 2. Register the Backend

Add the Akvorado backend to `config/grapher.php`:

```php
'providers' => [
    // ... existing backends ...
    'akvorado' => IXP\Services\Grapher\Backend\Akvorado::class,
],
```

Add the Akvorado config block inside the `backends` array:

```php
'backends' => [
    // ... existing backends ...

    'akvorado' => [
        // Akvorado API URL (required)
        'url'       => env( 'AKVORADO_URL', '' ),

        // HTTP request timeout in seconds
        'timeout'   => env( 'AKVORADO_TIMEOUT', 30 ),

        // Optional basic auth (if Akvorado is behind nginx auth)
        'auth_user' => env( 'AKVORADO_AUTH_USER', '' ),
        'auth_pass' => env( 'AKVORADO_AUTH_PASS', '' ),
    ],
],
```

### 3. Configure `.env`

```bash
# Add akvorado to your grapher backends (alongside existing backends)
# Akvorado handles: vlan, vlaninterface, p2p graph types
# Your existing backend (e.g. victoriametrics) continues to handle: physicalinterface, customer, etc.
GRAPHER_BACKENDS="victoriametrics|akvorado"

# Akvorado API URL
AKVORADO_URL="https://akvorado.example.com"

# Optional: increase timeout for large queries (default: 30s)
AKVORADO_TIMEOUT=30

# Optional: basic auth if your Akvorado is behind nginx auth
# AKVORADO_AUTH_USER=ixpmanager
# AKVORADO_AUTH_PASS=secret
```

### 4. Switch Views to uPlot (Skin Override)

The Akvorado backend returns data through the standard grapher framework, so existing views work out of the box. However, the legacy views render `<img>` tags pointing to PNG URLs — since Akvorado returns a 1x1 transparent PNG placeholder, you'll want to switch to uPlot rendering.

Create skin overrides for the affected views. In each, replace:

```php
<!-- Old: PNG image -->
<img src="<?= $t->graph->url() ?>" />
```

With:

```php
<!-- New: Interactive uPlot chart -->
<?= $t->graph->renderer()->boxUplot() ?>
```

Views that need skin overrides:

| View | Graph Type |
|------|-----------|
| `statistics/vlan.foil.php` | VLAN aggregate (per-exchange) |
| `statistics/p2p-single.foil.php` | P2P detail (single peer, 4 periods) |
| `statistics/p2ps.foil.php` | P2P overview (all peers for a customer) |
| `statistics/member.foil.php` | Member statistics (per-customer VLI graphs) |

### 5. P2P Daily Stats (Cron)

The P2P table page (`/statistics/p2p-table`) shows daily traffic totals per peer. This data is stored in the `p2p_daily_stats` database table and needs to be populated daily.

```bash
# Run once to backfill a specific day:
php artisan akvorado:upload-daily-p2p 2026-03-11

# Add to cron for automatic daily collection (runs for yesterday):
# /etc/cron.d/ixpmanager-akvorado
0 2 * * * www-data cd /srv/ixpmanager && php artisan akvorado:upload-daily-p2p >> /dev/null 2>&1
```

Options:
- `{day}` — Target day in `YYYY-MM-DD` format (defaults to yesterday)
- `--customer-id=N` — Process a single customer (for testing/debugging)
- `-v` — Show per-customer timing
- `-vv` — Show per-VLAN detail

**Performance:** Uses batch dimension queries — 2 API calls per VLAN per protocol per customer (vs 2 per peer in the old approach). A 170-member exchange completes in ~15-20 minutes.

### 6. Clear Config Cache

```bash
php artisan config:clear
```

If running with OPcache (`validate_timestamps=0`), restart your web server.

---

## How It Works

### Architecture

```
sflow from switches
        |
        +---> Akvorado (ClickHouse storage)
        |         |
        |         +---> IXP-Manager queries REST API
        |                +---> P2P graphs (per VLI pair)
        |                +---> Individual graphs (per VLI)
        |                +---> Aggregate graphs (per VLAN/exchange)
        |                +---> P2P daily stats table
        |
        +---> sflow-detect-ixp-bgp-sessions (unchanged)
```

### Traffic Identification

Akvorado stores raw sFlow samples with MAC addresses and VLAN tags. IXP-Manager maps these to customers using:

- **MAC addresses** — from the `l2address` table (configured per VlanInterface)
- **VLAN tags** — `vlaninterface.vlantag` if set (customer-specific tag before rewrite), else `vlan.number` (IXP exchange VLAN)

The combination of `SrcMAC + SrcVlan` uniquely identifies a customer on a specific exchange, even when the same MAC appears on multiple exchanges via the same physical port.

### sFlow Direction Model

sFlow is enabled **ingress-only** on peer ports. This means:

- **Outbound traffic** (customer A → customer B): Captured at A's ingress as `SrcMAC = A, DstMAC = B, SrcVlan = A's VLAN`
- **Inbound traffic** (customer B → customer A): Captured at B's ingress as `SrcMAC = B, DstMAC = A, SrcVlan = B's VLAN`

The backend makes two API calls per graph — one for each direction — and merges them.

### Query Types

| Graph Type | OUT Query | IN Query |
|-----------|-----------|----------|
| **P2P** | `SrcMAC = src AND DstMAC = dst AND SrcVlan = src_vlan AND EType = IPv4` | `SrcMAC = dst AND DstMAC = src AND SrcVlan = dst_vlan AND EType = IPv4` |
| **Individual** | `SrcMAC = mac AND SrcVlan = vlan AND EType = IPv4` | `DstMAC = mac AND DstVlan = vlan AND EType = IPv4` |
| **Aggregate** | `SrcVlan = ixp_vlan AND EType = IPv4` | (mirrored — same data in/out) |

### P2P Batch Optimization

The P2P overview page (showing mini-graphs for all peers) uses Akvorado's **dimension grouping** to fetch all peer traffic in just 2 API calls instead of 2 per peer:

- OUT: `SrcMAC = customer AND SrcVlan = vlan AND EType = IPv4` with `dimensions: ["DstMAC"]`
- IN: `DstMAC = customer AND EType = IPv4` with `dimensions: ["SrcMAC"]`

Each response row contains a MAC address that maps back to a peer customer. The `limit` parameter must be set high enough to cover all peers (we use `count(known_MACs) + 10`).

### Response Format

Akvorado's `/api/v0/console/graph/line` returns:

```json
{
  "t": ["2026-03-11T00:00:00Z", "2026-03-11T00:05:00Z", ...],
  "rows": [["AA:BB:CC:DD:EE:FF"], ["11:22:33:44:55:66"]],
  "points": [[1234567, 2345678, ...], [345678, 456789, ...]],
  "average": [1500000, 400000],
  "min": [500000, 100000],
  "max": [3000000, 800000],
  "95th": [2800000, 750000]
}
```

Key details:
- `rows` are **numeric arrays** (not associative) — access via `$row[0]`, not `$row['DstMAC']`
- MACs are returned **uppercase** — normalize with `strtolower()` before matching
- `average`/`max` arrays have one value per dimension row — used for daily stats calculation
- `points` arrays have one sub-array per dimension row, each containing time-series values
- Filter syntax: `SrcMAC = aa:bb:cc:dd:ee:ff` (no quotes around MAC values), `EType = IPv4` (named, not numeric `0x0800`)

### Caching

Live graph queries are cached for 5 minutes using Laravel's cache (keyed on filter + period + units, rounded to 5-minute windows). The daily stats command (`akvorado:upload-daily-p2p`) does not use caching.

### Daily Stats Calculation

For the P2P daily stats table, traffic totals are derived from Akvorado's per-row `average` field:

```
total_bytes = average_bps * 86400 / 8
```

Where `86400` is seconds per day and `/8` converts bits to bytes. Peak rates come directly from the `max` field.

---

## VLAN Resolution Detail

sFlow captures packets at ingress **before VLAN rewrite**. Customers may have port-specific VLAN tagging:

| Scenario | sFlow Sees | IXP-Manager Has |
|----------|-----------|----------------|
| Trunk port with vlantag 123 for Sydney (VLAN 200) | VLAN 123 | `vlaninterface.vlantag = 123` |
| Trunk port with vlantag 456 for Adelaide (VLAN 300) | VLAN 456 | `vlaninterface.vlantag = 456` |
| Untagged port on Sydney | VLAN 200 | `vlaninterface.vlantag = NULL`, `vlan.number = 200` |

Resolution logic in `AkvoradoService::resolveVlan()`:

```php
if ($vli->vlantag !== null && $vli->vlantag > 0) {
    return $vli->vlantag;  // customer-facing tag (pre-rewrite)
}
return $vli->vlan->number;  // IXP exchange VLAN
```

For **aggregate** (per-exchange) graphs, the IXP VLAN number is used directly — this covers all traffic on the exchange regardless of per-port vlantag rewriting.

---

## File Reference

```
app/
├── Services/
│   ├── Akvorado/
│   │   └── AkvoradoService.php          # HTTP client, query builder, MAC/VLAN resolution
│   └── Grapher/
│       └── Backend/
│           └── Akvorado.php             # Grapher backend (implements Backend contract)
└── Console/
    └── Commands/
        └── Grapher/
            ├── UploadDailyP2pAkvorado.php  # Daily P2P stats cron command
            └── AkvoradoCheckMacs.php        # Rogue MAC detection command

config/
└── grapher.php                          # Backend registration + Akvorado config

resources/skins/<your-skin>/
└── statistics/
    ├── vlan.foil.php                    # VLAN aggregate graphs (uPlot)
    ├── p2p-single.foil.php              # P2P detail graphs (uPlot)
    └── p2ps.foil.php                    # P2P overview with batch queries
```

### AkvoradoService Methods

| Method | Purpose |
|--------|---------|
| `queryTimeSeries()` | Rolling-window query with caching (for live graphs) |
| `queryDateRange()` | Fixed start/end query without caching (for batch/cron) |
| `p2pTraffic()` | P2P graph data for a single src/dst VLI pair |
| `p2pBatchTraffic()` | P2P data for ALL peers via dimension grouping (2 API calls) |
| `p2pDailyStats()` | Daily stats (totals + peaks) for all peers via dimension grouping |
| `individualTraffic()` | Per-VLI aggregate traffic |
| `aggregateTraffic()` | Per-VLAN/exchange aggregate traffic |
| `resolveVlan()` | VLI → VLAN tag for Akvorado filter |
| `resolveMACs()` | VLI → configured MAC addresses |

---

## MAC Anomaly Detection

The `akvorado:check-macs` command detects rogue or unknown MAC addresses by comparing MACs seen in Akvorado sFlow data against IXP-Manager's configured MAC addresses (`l2address` table).

### How It Works

1. Loads all configured MACs from the `l2address` table (and optionally learned MACs from `macaddress`)
2. For each public VLAN, queries Akvorado: `SrcVlan = <vlan> AND InIfBoundary = external` grouped by `SrcMAC`
3. Any MAC seen in sFlow but not in IXP-Manager is flagged as unknown
4. Results sorted by traffic volume — noisiest rogues first

### Usage

```bash
# Basic check (last 6 hours, all public VLANs)
php artisan akvorado:check-macs

# Custom lookback period
php artisan akvorado:check-macs --period=24h

# Check a specific VLAN only
php artisan akvorado:check-macs --vlan=42

# Include learned MACs (macaddress table) as "known" — reduces false positives
php artisan akvorado:check-macs --include-learned

# JSON output for automation/alerting pipelines
php artisan akvorado:check-macs --json
```

### Output

Console output shows unknown MACs grouped by VLAN with traffic rates:

```
Checking for unknown MACs (period: 6h, known MACs: 342)

Sydney Peering LAN: 2 unknown MAC(s)
+-------------------+------+----------+----------+
| MAC Address       | VLAN | Avg Rate | Max Rate |
+-------------------+------+----------+----------+
| aa:bb:cc:dd:ee:ff | 200  | 1.23 Gbps| 3.45 Gbps|
| 11:22:33:44:55:66 | 200  | 45.6 Kbps| 120 Kbps |
+-------------------+------+----------+----------+

Total: 2 unknown MAC(s) detected across 1 VLAN(s)
```

JSON output (`--json`) returns the same data structured for parsing:

```json
{
  "Sydney Peering LAN": [
    { "mac": "aa:bb:cc:dd:ee:ff", "vlan": 200, "avg_bps": 1230000000, "max_bps": 3450000000 },
    { "mac": "11:22:33:44:55:66", "vlan": 200, "avg_bps": 45600, "max_bps": 120000 }
  ]
}
```

### Exit Codes

- `0` — No unknown MACs detected (clean)
- `1` — Unknown MACs found

This makes it easy to use in monitoring/alerting:

```bash
# Alert via email when rogues detected
php artisan akvorado:check-macs || php artisan akvorado:check-macs | mail -s "IXP MAC Alert" noc@example.com
```

### Cron Schedule

```bash
# /etc/cron.d/ixpmanager-mac-check
*/15 * * * * www-data cd /srv/ixpmanager && php artisan akvorado:check-macs >> /var/log/ixp-mac-check.log 2>&1
```

### Notes

- The `InIfBoundary = external` filter ensures only customer-facing ports are checked — internal/core links are excluded
- The `--include-learned` flag adds MACs from the `macaddress` table (populated by `sflow-detect-ixp-bgp-sessions` or SNMP polling). This reduces false positives from legitimate MACs that haven't been manually configured yet.
- The `limit` is set to 1000 per VLAN query. If you have more than ~990 unique source MACs on a single VLAN, increase this in the command source.
- Low-traffic unknown MACs (e.g. spanning-tree BPDUs, LLDP) may appear — filter by `avg_bps` if needed.

---

## Troubleshooting

### No data on graphs

1. **Check Akvorado connectivity:** `curl -X POST https://akvorado.example.com/api/v0/console/graph/line -H 'Content-Type: application/json' -d '{"start":"2026-03-11T00:00:00Z","end":"2026-03-12T00:00:00Z","filter":"SrcVlan = 200","units":"l3bps","points":10,"limit":5}'`
2. **Check MAC addresses:** Members must have configured MACs in the `l2address` table (via IXP-Manager admin UI → Interfaces → Layer 2 Addresses)
3. **Check Akvorado schema:** `SrcMAC`, `DstMAC`, `SrcVlan`, `DstVlan` must be enabled
4. **Check Laravel logs:** `tail -f storage/logs/laravel.log | grep Akvorado`

### Filter parse errors

- **MAC addresses:** No quotes — `SrcMAC = aa:bb:cc:dd:ee:ff` (not `SrcMAC = 'aa:bb:cc:dd:ee:ff'`)
- **EType:** Named values — `EType = IPv4` (not `EType = 2048` or `EType = 0x0800`)
- **VLAN:** Numeric — `SrcVlan = 200`

### P2P overview shows "No data" for all peers

- The `limit` parameter must be high enough to cover all peer MACs. If some peers are bucketed into "Other" in the response, they won't be mapped. Check that `limit >= count(unique_peer_MACs) + 10`.
- Verify MAC format: Akvorado returns **uppercase** MACs (e.g. `AA:BB:CC:DD:EE:FF`). The code normalizes with `strtolower()` — if you modify the mapping code, ensure case-insensitive matching.
- Dimension rows are **numeric arrays** (`["AA:BB:CC:DD:EE:FF"]`), not associative. Access via `$row[0]`, not `$row['DstMAC']`.

### P2P daily stats table is empty

1. Run manually: `php artisan akvorado:upload-daily-p2p 2026-03-11 -v`
2. Check that Akvorado has data for that date (retention depends on ClickHouse materialized views)
3. Verify the `p2p_daily_stats` migration has been run: `php artisan migrate`

### Timeouts on large exchanges

- Increase `AKVORADO_TIMEOUT` in `.env` (default 30s)
- For the daily stats command, each customer makes 2-4 API calls — large exchanges (170+ members) may take 15-20 minutes total

---

## Akvorado Retention

Akvorado's data retention depends on ClickHouse materialized views. Default configuration:

| Resolution | Retention |
|-----------|-----------|
| Raw (5s) | 15 days |
| 5-minute | 3 months |
| 1-hour | 1 year |

Ensure your retention config covers the graph periods you need (day/week/month/year). The `year` period queries 365 days of data at 365 points — this requires at least 1-year retention at hourly resolution.

---

## Decisions & Rationale

1. **Akvorado REST API** (not direct ClickHouse) — provides a stable abstraction layer; the OVH Grafana plugin uses the same endpoint
2. **Standard grapher framework** — plugs into existing `canProcess()` / `data()` / `statistics()` pipeline, so all existing renderers and stats calculations work unchanged
3. **Batch dimension queries** for P2P overview — 2 API calls instead of 2N, critical for exchanges with 100+ members
4. **Daily stats via cron** (not live query) — the P2P table needs totals for a specific calendar day; a nightly cron job populates the DB table, keeping the page fast
5. **5-minute cache** for live graphs — balances freshness vs API load; keyed on filter+period+units with time-window bucketing
