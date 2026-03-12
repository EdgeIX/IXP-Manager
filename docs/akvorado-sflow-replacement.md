# Akvorado sFlow Replacement

> Replace the legacy Perl sflow-to-rrd-handler with Akvorado API queries for p2p traffic graphs, rendered with uPlot. Eliminates RRD files and one of the two IXP-Manager sflow fanout targets.

---

## Current Architecture

```
sflow from switches
        |
        +---> Akvorado (NOC dashboards)
        |
        +---> IXP-Manager sflowtool (x2)
               +---> jix-sflow-to-rrd-handler --> RRD files --> p2p traffic graphs (PNG)
               +---> sflow-detect-ixp-bgp-sessions --> bgpsessiondata table --> bilateral matrix
```

### Current Components (to be replaced)

| Component | Location | Purpose |
|-----------|----------|---------|
| `jix-interface-map-dump` | `tools/runtime/sflow/` | Perl script, queries DB to build `switch_ip -> ifIndex -> {ixp_vlan, interface_name, egress_vlan}` JSON map |
| `jix-sflow-to-rrd-handler` | `tools/runtime/sflow/` | Perl daemon, reads sflowtool output, translates VLANs via interface map, maps MAC -> VLI via API, writes RRD files |
| `Sflow.php` backend | `app/Services/Grapher/Backend/` | PHP, reads RRD files to render PNG p2p/aggregate/individual graphs |
| RRD files | `/srv/ixpmatrix/` | On-disk round-robin databases: p2p, individual, aggregate per VLI/VLAN |
| `sflow-db-mapper` API | `/api/v4/sflow-db-mapper/` | Returns `infrastructure -> vlan -> mac -> vli_id` mapping |

### Retained (unchanged)

| Component | Purpose |
|-----------|---------|
| `sflow-detect-ixp-bgp-sessions` | BGP bilateral detection via TCP/179 sflow samples -> `bgpsessiondata` table |
| Akvorado | Existing sflow collector, already deployed for NOC |

---

## Target Architecture

```
sflow from switches
        |
        +---> Akvorado (NOC + IXP-Manager p2p source)
        |         |
        |         +---> IXP-Manager queries Akvorado REST API
        |                +---> p2p graphs (uPlot, per VLI pair)
        |                +---> individual graphs (uPlot, per VLI)
        |                +---> aggregate graphs (uPlot, per VLAN/exchange)
        |                +---> MAC anomaly detection
        |
        +---> sflow-detect-ixp-bgp-sessions (stays as-is)
```

---

## Akvorado API

### Endpoint

```
POST /api/v0/console/graph/line
```

> **Note:** API is `v0` (unstable) but being stabilized for Akvorado 2.0. The OVH Grafana plugin uses the same endpoint as a reference implementation.

### Request Format

```json
{
  "start": "2026-03-11T00:00:00Z",
  "end": "2026-03-12T00:00:00Z",
  "dimensions": ["SrcMAC", "DstMAC"],
  "filter": "SrcMAC = '00:1a:2b:3c:4d:5e' AND DstMAC = '00:5e:4d:3c:2b:1a' AND SrcVlan = 123",
  "limit": 10,
  "units": "l3bps",
  "points": 200
}
```

### Response Format

```json
{
  "t": [1709200000, 1709200300, ...],
  "rows": [["00:1a:2b:3c:4d:5e", "00:5e:4d:3c:2b:1a"]],
  "points": [[1234567, 2345678, ...]],
  "axis": [...],
  "stats": [{"avg": 1500000, "min": 500000, "max": 3000000, "last": 1200000, "95th": 2800000}]
}
```

### Available Units

- `l3bps` — Layer 3 bits/s
- `l2bps` — Layer 2 bits/s
- `pps` — Packets/s
- `inl2%` — Ingress utilization %
- `outl2%` — Egress utilization %

### Akvorado Schema (already configured)

```yaml
# /srv/akvorado/config/akvorado.yaml
schema:
  enabled:
    - SrcVlan
    - DstVlan
    - SrcMAC
    - DstMAC
    - SrcNetPrefix
    - DstNetPrefix
```

---

## VLAN Resolution

### The Problem

sFlow samples at ingress **before VLAN rewrite**. Customers may have port-specific VLAN tagging:

- Trunk port with `vlantag = 123` for Sydney (IXP VLAN 200) -> sFlow sees VLAN 123
- Trunk port with `vlantag = 456` for Adelaide (IXP VLAN 300) -> sFlow sees VLAN 456
- Untagged port on Sydney -> sFlow sees VLAN 200 (the IXP VLAN directly)

A customer can peer on multiple exchanges from the **same physical port on the same switch** (same ExporterName, same InIfName, same MAC). The only differentiator is the VLAN tag.

### The Solution

IXP-Manager already has both values:

- `vlaninterface.vlantag` = customer-facing tag (123, 456) or `NULL` for untagged
- `vlan.number` = IXP internal peering VLAN (200, 300)

Query logic:

```
For a given VlanInterface:
  if vlantag is set  -> filter SrcVlan = vlantag     (e.g., 123)
  if vlantag is NULL -> filter SrcVlan = vlan.number  (e.g., 200)
```

### Same MAC, Multiple Exchanges

Valid scenario: Customer X peers on Sydney (VLAN 200) and Adelaide (VLAN 300) with the same MAC.

Resolution: `SrcMAC + SrcVlan` uniquely identifies the customer on a specific exchange. The VLAN (resolved per above) scopes the query to a single exchange.

### Same MAC, Same VLAN

Invalid scenario: enforced by IXP-Manager. Same MAC must not appear twice on the same VLAN.

---

## MAC Anomaly Detection

### Concept

Periodically query Akvorado for distinct MACs seen per interface/VLAN. Compare against IXP-Manager's `l2address` table (configured/static MACs). Any MAC not in the table is a potential rogue.

### Query

```json
{
  "start": "<now - 6h>",
  "end": "<now>",
  "dimensions": ["SrcMAC", "SrcVlan", "ExporterName", "InIfName"],
  "filter": "ExporterName = 'pe1syd3'",
  "units": "l3bps",
  "limit": 1000
}
```

### Detection Logic

1. Fetch all distinct `SrcMAC` values seen in Akvorado for a given time window
2. Load all known MACs from `l2address` table (configured) and optionally `macaddress` table (learned)
3. Diff: any MAC in Akvorado but not in IXP-Manager is flagged
4. Group by `SrcVlan` + `InIfName` to identify which port/exchange the rogue appeared on
5. Output: admin alert, dashboard panel, or email notification

### Implementation

- Artisan command: `php artisan akvorado:check-macs`
- Can be scheduled via cron (e.g., every 15 minutes)
- Could feed into existing IXP-Manager notification system

---

## Graph Types to Replace

### 1. P2P (Peer-to-Peer)

Traffic between two specific VlanInterfaces on the same exchange.

**Current:** RRD file per `srcvli/dstvli` pair, rendered as PNG
**New:** Akvorado query: `SrcMAC + DstMAC + SrcVlan` -> uPlot chart

### 2. Individual (Per-VlanInterface)

Aggregate in/out traffic for a single VlanInterface across all peers.

**Current:** RRD file per `srcvli`, rendered as PNG
**New:** Akvorado query: `SrcMAC + SrcVlan` (in) / `DstMAC + SrcVlan` (out) -> uPlot chart

### 3. Aggregate (Per-VLAN/Exchange)

Total traffic across an entire exchange VLAN.

**Current:** RRD file per VLAN, rendered as PNG
**New:** Akvorado query: `SrcVlan = <ixp_vlan>` -> uPlot chart
**Note:** For aggregate, use the IXP VLAN number directly — covers all traffic on that exchange regardless of per-port vlantag rewriting.

---

## Implementation Components

### 1. Grapher Backend — `Akvorado.php`

**File:** `app/Services/Grapher/Backend/Akvorado.php`

New grapher backend alongside Sflow/Mrtg/VictoriaMetrics. Implements the same `canProcess()` interface for graph types: `P2p`, `VlanInterface` (individual), `Vlan` (aggregate).

### 2. AkvoradoService — HTTP client + query builder

**File:** `app/Services/Akvorado/AkvoradoService.php`

**Key methods:**
- `queryTimeSeries(array $filter, string $unit, string $start, string $end, int $points): array`
- `p2pTraffic(VlanInterface $src, VlanInterface $dst, string $period): array`
- `individualTraffic(VlanInterface $vli, string $period): array`
- `aggregateTraffic(Vlan $vlan, string $period): array`
- `resolveVlan(VlanInterface $vli): int` — returns vlantag if set, else vlan.number
- `resolveMACs(VlanInterface $vli): array` — returns configured l2address MACs for a VLI
- `distinctMACs(string $exporterName, string $period): array` — for anomaly detection

### 3. Config — `config/grapher.php`

Add `akvorado` backend config:

```php
'akvorado' => [
    'enabled'  => env('AKVORADO_ENABLED', false),
    'url'      => env('AKVORADO_URL', 'http://localhost:8080'),
    'timeout'  => env('AKVORADO_TIMEOUT', 10),
    'periods'  => [
        'hour'  => ['range' => '1h',  'points' => 240],
        'day'   => ['range' => '24h', 'points' => 288],
        'week'  => ['range' => '7d',  'points' => 336],
        'month' => ['range' => '30d', 'points' => 360],
        'year'  => ['range' => '365d','points' => 365],
    ],
],
```

### 4. uPlot P2P Renderer (skin override)

**File:** `resources/skins/edgeix/services/grapher/renderer/box/p2p.foil.php` (new)

- Period selector (Hour/Day/Week/Month/Year)
- AJAX fetch to new API endpoint
- uPlot chart with green RX / blue TX (matching existing traffic graph style)
- Stats row: max, avg, current, 95th percentile (Akvorado provides these)
- SI-unit Y-axis formatter (bits/s)
- Lazy-load uPlot via shared queue pattern

### 5. API Endpoints

```php
// New routes for AJAX graph data
Route::get('/api/v4/grapher/p2p/{srcVli}/{dstVli}', 'GrapherController@p2pAkvorado');
Route::get('/api/v4/grapher/individual/{vli}', 'GrapherController@individualAkvorado');
Route::get('/api/v4/grapher/aggregate/{vlan}', 'GrapherController@aggregateAkvorado');
```

### 6. MAC Anomaly Command

**File:** `app/Console/Commands/AkvoradoCheckMacs.php`

```php
php artisan akvorado:check-macs [--period=6h] [--notify]
```

### File Layout Summary

```
IXP-Manager (EdgIX fork)
├── app/Services/Grapher/Backend/Akvorado.php      # grapher backend
├── app/Services/Akvorado/AkvoradoService.php      # HTTP client + query builder
├── app/Console/Commands/AkvoradoCheckMacs.php      # rogue MAC detection
├── config/grapher.php                              # add akvorado backend config
└── resources/skins/edgeix/
    └── services/grapher/renderer/box/p2p.foil.php  # uPlot renderer
```

---

## Migration Path

### Phase 1: Read-only (parallel operation)
- Deploy AkvoradoService + uPlot renderer
- Keep RRD pipeline running
- New graphs render from Akvorado, old graphs still available
- Validate data matches between RRD and Akvorado

### Phase 2: Switchover
- Disable sflow fanout to jix-sflow-to-rrd-handler
- Remove RRD pipeline (handler, interface-map-dump, rrdcached)
- Akvorado is sole source for p2p/individual/aggregate graphs

### Phase 3: Enhancements
- MAC anomaly detection command + cron schedule
- Admin dashboard panel for rogue MAC alerts

### Out of Scope (use Akvorado UI directly)
- Prefix-level traffic breakdown (SrcNetPrefix/DstNetPrefix)
- Country/geo analysis (SrcCountry/DstCountry)
- Protocol/port breakdown (Proto/DstPort)
- Packet size distribution (PacketSizeBucket)
- Sankey flow diagrams
- ASN-level deep-dive analysis

These are all available in the Akvorado console and don't need to be duplicated in IXP-Manager. IXP-Manager's value-add is context enrichment: mapping Akvorado's raw flow data (MACs, VLANs) to customers, exchanges, and authorised MAC tables — things Akvorado doesn't know about.

---

## Decisions

1. **Use Akvorado API** (not direct ClickHouse). Accept v0 instability risk; monitor Akvorado 2.0 for stable API.
2. **Keep IPv4/IPv6 split** — filter by `EType` (0x0800 for IPv4, 0x86DD for IPv6), matching current RRD behaviour.
3. **Core IXP-Manager** (EdgIX fork) — not the pseudowire module. P2P graphs are a core feature; Akvorado is a new grapher backend alongside Sflow/Mrtg/VictoriaMetrics. uPlot renderer in EdgIX skin.

## Open Questions

1. **Akvorado API stability** — v0 API may change. Monitor Akvorado 2.0 release for stable API.
2. **Historical data** — Akvorado retention depends on ClickHouse materialized views (default: 15 days raw, 3 months @ 5min, 1 year @ 1h). Ensure retention config matches current RRD retention.
3. **Sampling accuracy** — sFlow is sampled (1:N). Akvorado applies the same sample rate multiplication. Values are statistical estimates, same as current RRD approach.
