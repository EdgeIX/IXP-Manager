# VictoriaMetrics Grapher Backend for IXP Manager

A drop-in replacement for the MRTG/RRD grapher backend in [IXP Manager](https://www.ixpmanager.org/), powered by [VictoriaMetrics](https://victoriametrics.com/) and interactive [uPlot](https://github.com/leeoniya/uPlot) charts.

Replaces legacy server-rendered MRTG PNG graphs with client-side interactive time-series charts, backed by streaming telemetry data stored in VictoriaMetrics (or any Prometheus-compatible TSDB).

## Features

- **Interactive charts** -- zoom, hover tooltips, responsive resizing (uPlot, ~50KB JS)
- **All five graph categories** -- Bits, Packets, Errors, Discards, Broadcasts
- **Eight graph scopes** -- Physical Interface, Virtual Interface (LAG/Port-Channel), Customer Aggregate, IXP-wide, Infrastructure, Switch, Location, Core Bundle
- **Four time periods** -- Day, Week, Month, Year
- **Category-aware colour schemes** -- distinct palettes for each metric type, with separate aggregate/physical variants
- **Recording rule powered** -- queries pre-computed recording rule gauges for maximum performance; no `rate()` at query time, no sample limit issues even at year-long time ranges. Metric names are fully configurable for portability across deployments
- **Pluggable** -- uses IXP Manager's existing `Grapher\Backend` contract; can run alongside MRTG as a fallback
- **DOM (optical power) monitoring** -- RX/TX optical power (dBm) graphs on every physical port and core link, with multi-channel support for breakout optics
- **Interface status overlay** -- red shading on traffic graphs during interface down periods (from `openconfig_interfaces_oper_status`)
- **Capacity utilization badges** -- colour-coded percentage badges on port and LAG headers (green <50%, yellow 50-80%, red >80%); LAGs show aggregate utilization across all member ports
- **Top-N dashboard** -- admin-only page showing the busiest ports by current traffic rate, with customer, port, location, speed, and utilization columns

## Architecture

```
gNMI Streaming Telemetry (Arista EOS / etc.)
        |
        v
    gNMIC collector
        |
        v
  VictoriaMetrics (PromQL-compatible TSDB)
        |
        v
  IXP Manager VictoriaMetrics Backend (PromQL queries via HTTP)
        |
        v
  uPlot Renderer (client-side interactive charts)
```

### Data Pipeline

1. **Collection**: gNMIC (or similar) streams OpenConfig interface counters from switches into VictoriaMetrics
2. **Recording rules**: VM/Prometheus recording rules pre-compute `rate()` and enrichment at write time, producing gauge metrics (e.g. `port_bitrate_rx:10s`)
3. **Query**: The backend queries these pre-computed gauges via `query_range` — no `rate()` at query time
4. **Render**: The uPlot Foil template renders interactive JavaScript charts client-side

The backend queries pre-computed recording rule gauges rather than raw counters. This eliminates `rate()` computation at query time, vastly reducing sample scanning and avoiding VictoriaMetrics `maxSamplesPerQuery` errors even on year-long aggregate queries across many switches.

## Prerequisites

- IXP Manager v6.x+ (PHP 8.1+, Laravel 9+)
- VictoriaMetrics (or Prometheus) with port traffic metrics
- Streaming telemetry collector (gNMIC recommended) feeding metrics into VM
- uPlot v1.6.31 vendored at `public/vendor/uplot/`

### Required Recording Rules

The backend queries pre-computed recording rule gauge metrics. Recording rules apply `rate()` and multipliers (e.g. `rate(counter[30s])*8`) at write time, so the backend queries them as simple gauges — no `rate()` at query time.

Each recording rule metric must carry a `device_interface` label in the format `switch_name:port_name` (e.g. `pe1syd3:Ethernet9/2`), plus `device` and `interface_name` labels for aggregate queries.

| Category    | RX Metric (default)            | TX Metric (default)             | Unit  |
|-------------|--------------------------------|---------------------------------|-------|
| Bits        | `port_bitrate_rx:10s`          | `port_bitrate_tx:10s`           | bps   |
| Packets     | `port_unicast_pps_rx:10s`      | `port_unicast_pps_tx:10s`       | pps   |
| Errors      | `port_errors_pps_rx:10s`       | `port_errors_pps_tx:10s`        | pps   |
| Discards    | `port_discards_pps_rx:10s`     | `port_discards_pps_tx:10s`      | pps   |
| Broadcasts  | `port_broadcasts_pps_rx:10s`   | `port_broadcasts_pps_tx:10s`    | pps   |

These metrics are gauge values (already rate-computed). The metric names are configurable via `config/grapher.php` or `.env` variables — see [Configuration Reference](#configuration-reference).

#### Example Recording Rules (VictoriaMetrics/Prometheus)

```yaml
groups:
  - name: ixp_port_metrics
    interval: 10s
    rules:
      - record: port_bitrate_rx:10s
        expr: >
          rate(openconfig_interfaces_in_octets[30s]) * 8
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_bitrate_tx:10s
        expr: >
          rate(openconfig_interfaces_out_octets[30s]) * 8
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_unicast_pps_rx:10s
        expr: >
          rate(openconfig_interfaces_in_unicast_pkts[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_unicast_pps_tx:10s
        expr: >
          rate(openconfig_interfaces_out_unicast_pkts[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_errors_pps_rx:10s
        expr: >
          rate(openconfig_interfaces_in_errors[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_errors_pps_tx:10s
        expr: >
          rate(openconfig_interfaces_out_errors[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_discards_pps_rx:10s
        expr: >
          rate(openconfig_interfaces_in_discards[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_discards_pps_tx:10s
        expr: >
          rate(openconfig_interfaces_out_discards[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_broadcasts_pps_rx:10s
        expr: >
          rate(openconfig_interfaces_in_broadcast_pkts[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port

      - record: port_broadcasts_pps_tx:10s
        expr: >
          rate(openconfig_interfaces_out_broadcast_pkts[30s])
          * on (device_interface)
          group_left(customer, asn, member, bundle, bundle_parent)
          ixpmanager_port
```

The `ixpmanager_port` join metric enriches each series with customer/ASN metadata. This is optional for the grapher backend (which only needs the `device_interface`, `device`, and `interface_name` labels) but useful for Grafana dashboards and alerting.

#### Sub-interface Recording Rules

If your IXP uses shared trunk ports where multiple customers share a Port-Channel via VLAN sub-interfaces (e.g. `Port-Channel2.502`), you also need sub-interface recording rules. These are used by both the core grapher (for peering sub-interface ports) and the `edgeix/ixpm-pseudowire` module:

```yaml
      - record: svc_bitrate_rx:10s
        expr: >
          rate(openconfig_subinterfaces_in_octets[30s]) * 8
          * on (device_interface)
          group_left(customer, asn, parent_interface, type)
          ixpmanager_svc

      - record: svc_bitrate_tx:10s
        expr: >
          rate(openconfig_subinterfaces_out_octets[30s]) * 8
          * on (device_interface)
          group_left(customer, asn, parent_interface, type)
          ixpmanager_svc
```

The `device_interface` label format for sub-interfaces includes the sub-interface index: `switch_name:Port-Channel<n>.<index>` (e.g. `pe1adl3:Port-Channel2.502`). The sub-interface index is already baked into the SwitchPort name in IXP Manager — it is **not** the peering VLAN number (which may differ).

**Note:** Ports not covered by the `ixpmanager_svc` enrichment metric (e.g. pseudowire sub-interfaces) will fall back to raw `rate(openconfig_subinterfaces_*_octets{...}[30s])*8` queries automatically.

### Optional: DOM (Optical Power) Metrics

For DOM monitoring, the following enriched metrics must be present with `device` + `interface_name` labels:

| Metric                  | Description                  | Unit | Labels                                   |
|-------------------------|------------------------------|------|------------------------------------------|
| `dom_rx_power:interface` | RX optical power            | dBm  | `device`, `interface_name`, `channel_index` |
| `dom_tx_power:interface` | TX optical power            | dBm  | `device`, `interface_name`, `channel_index` |

These are **gauge values** (not counters), so no `rate()` is applied. The `channel_index` label supports multi-lane optics (breakouts).

**Note:** The DOM metrics use separate `device` and `interface_name` labels, not the combined `device_interface` label used by traffic counters. This is because DOM data comes from the OpenConfig transceiver subscription which uses `component_name` (chip-level) rather than the interface hierarchy. A recording rule or relabelling step maps the component to its associated interface.

### Optional: Interface Status Metrics

For interface status overlays on traffic graphs:

| Metric                            | Description        | Values | Labels              |
|-----------------------------------|--------------------|--------|---------------------|
| `openconfig_interfaces_oper_status` | Operational state | 1=UP   | `device_interface`  |

This is a gauge value. When the status is anything other than 1, the traffic graph background is shaded red to indicate a down period.

### Label Format

The raw OpenConfig counters must include these labels:

| Label              | Description                                    | Example                        |
|--------------------|------------------------------------------------|--------------------------------|
| `device_interface` | `switch_name:port_name`                        | `pe1syd3:Ethernet9/2`          |
| `device`           | Switch name (matches IXP Manager Switch `name`) | `pe1syd3`                      |
| `interface_name`   | Port name only                                 | `Ethernet9/2`                  |

**Important**: `device` / the switch name portion of `device_interface` comes from the Switch model's `name` field in IXP Manager (not the `hostname` field, which is typically the management IP/FQDN).

#### Per-Port Graphs (Physical, Virtual, Customer)

These use the `device_interface` label for exact or regex matching:

| Graph Type         | Label Format                          | Example                        |
|--------------------|---------------------------------------|--------------------------------|
| Physical Interface | `switch_name:port_name`               | `pe1syd3:Ethernet9/2`          |
| LAG (Port-Channel) | `switch_name:Port-Channel<group>`     | `pe1syd3:Port-Channel42`       |
| Single-member VI   | `switch_name:port_name`               | `pe1syd3:Ethernet8/3`          |

For **Customer Aggregate** graphs, the backend queries all of a customer's interfaces using PromQL regex alternation:
```promql
sum(port_bitrate_rx:10s{device_interface=~"pe1syd3:Port-Channel42|pe2syd3:Ethernet5/1"})
```

#### Aggregate Graphs (IXP, Infrastructure, Switch, Location)

These use the `device` and `interface_name` labels, summing across all Ethernet interfaces on the relevant switches. The `interface_name=~"Ethernet.*"` filter avoids double-counting LAG members alongside Port-Channel aggregates (Port-Channel interfaces don't match `Ethernet.*`):

| Graph Type     | PromQL Pattern                                                           |
|----------------|--------------------------------------------------------------------------|
| IXP-wide       | `sum(metric{interface_name=~"Ethernet.*"})`                              |
| Infrastructure | `sum(metric{device=~"sw1\|sw2",interface_name=~"Ethernet.*"})`           |
| Switch         | `sum(metric{device="name",interface_name=~"Ethernet.*"})`                |
| Location       | `sum(metric{device=~"sw1\|sw2",interface_name=~"Ethernet.*"})`           |

Since recording rules are pre-computed gauges, these queries are simple `sum()` lookups — no `rate()` at query time. This means IXP-wide aggregate queries work efficiently even at year-long time ranges across hundreds of interfaces.

#### Three-Tier Metric Resolution

The backend uses a three-tier approach to find the right metric for each port type:

| Tier | Metric | Used For | Example |
|------|--------|----------|---------|
| 1. Port recording rules | `port_bitrate_rx:10s` | Dedicated physical ports, Port-Channels | `pe1syd3:Ethernet9/2` |
| 2. Sub-interface recording rules | `svc_bitrate_rx:10s` | VLAN sub-interfaces on shared trunks | `pe1adl3:Port-Channel2.502` |
| 3. Raw counter fallback | `rate(openconfig_*[30s])*8` | Core links, ports not in enrichment metrics | `pe1syd3:Ethernet25/1` |

Sub-interface ports are detected by checking for a `.` in the SwitchPort name. The sub-interface index (e.g. `.502`) is already part of the SwitchPort name in IXP Manager — it is **not** derived from the peering VLAN number (which may be a different value).

For **Customer Aggregate** graphs with mixed port types (some dedicated, some sub-interfaces), the backend builds separate queries for each group and combines them with PromQL addition:
```promql
(sum(port_bitrate_rx:10s{device_interface="pe1:Ethernet9/2"})) + (sum(svc_bitrate_rx:10s{device_interface=~"pe2:Port-Channel5\\.401|pe3:Port-Channel1\\.202"}))
```

#### Core Bundle Graphs (inter-switch links)

Core bundle graphs sum across the member port interfaces for a given side (A or B):

| Graph Type     | PromQL Pattern                                                           |
|----------------|--------------------------------------------------------------------------|
| Core Bundle (aggregate) | `sum(metric{device_interface=~"pe1:Eth25/1\|pe1:Eth26/1"})` |
| Core Bundle (single link) | `metric{device_interface="pe1:Ethernet25/1"}` |
| Individual member port | `metric{device_interface="pe1:Ethernet25/1"}` |

Core bundles walk the relationship chain: `CoreBundle → CoreLinks → CoreInterface(side) → PhysicalInterface → SwitchPort → Switcher` to resolve port labels.

The backend resolves switches for each scope via IXP Manager's model relationships:
- **Infrastructure** → `Infrastructure->switchers` (HasMany)
- **Location** → `Location->cabinets->switchers` (HasMany through Cabinet)
- **Switch** → direct match on `Switcher->name`
- **Core Bundle** → `CoreBundle->corelinks->coreInterfaceSide{A|B}->physicalInterface->switchPort->switcher`

## Installation

### 1. Add the Backend Class

Copy `app/Services/Grapher/Backend/VictoriaMetrics.php` into your IXP Manager installation.

### 2. Register the Backend

In `config/grapher.php`, add to the `providers` array:

```php
'providers' => [
    // ... existing providers
    'victoriametrics' => \IXP\Services\Grapher\Backend\VictoriaMetrics::class,
],
```

And add the backend-specific config section:

```php
'backends' => [
    // ... existing backend configs

    'victoriametrics' => [
        'url' => env( 'GRAPHER_BACKEND_VM_URL', 'http://localhost:8428' ),

        // Recording rule metric names (override via .env if your rules use different names)
        'metrics' => [
            'bits' => [
                'rx' => env( 'GRAPHER_VM_METRIC_BITS_RX', 'port_bitrate_rx:10s' ),
                'tx' => env( 'GRAPHER_VM_METRIC_BITS_TX', 'port_bitrate_tx:10s' ),
            ],
            'packets' => [
                'rx' => env( 'GRAPHER_VM_METRIC_PKTS_RX', 'port_unicast_pps_rx:10s' ),
                'tx' => env( 'GRAPHER_VM_METRIC_PKTS_TX', 'port_unicast_pps_tx:10s' ),
            ],
            'errors' => [
                'rx' => env( 'GRAPHER_VM_METRIC_ERRS_RX', 'port_errors_pps_rx:10s' ),
                'tx' => env( 'GRAPHER_VM_METRIC_ERRS_TX', 'port_errors_pps_tx:10s' ),
            ],
            'discards' => [
                'rx' => env( 'GRAPHER_VM_METRIC_DISC_RX', 'port_discards_pps_rx:10s' ),
                'tx' => env( 'GRAPHER_VM_METRIC_DISC_TX', 'port_discards_pps_tx:10s' ),
            ],
            'broadcasts' => [
                'rx' => env( 'GRAPHER_VM_METRIC_BCAST_RX', 'port_broadcasts_pps_rx:10s' ),
                'tx' => env( 'GRAPHER_VM_METRIC_BCAST_TX', 'port_broadcasts_pps_tx:10s' ),
            ],
        ],

        // Sub-interface recording rules (for VLAN sub-interfaces on shared trunk ports)
        'subinterface_metrics' => [
            'bits' => [
                'rx' => env( 'GRAPHER_VM_SUBINT_BITS_RX', 'svc_bitrate_rx:10s' ),
                'tx' => env( 'GRAPHER_VM_SUBINT_BITS_TX', 'svc_bitrate_tx:10s' ),
            ],
        ],
    ],
],
```

### 3. Enable via Environment

In your `.env` file:

```env
# Use VictoriaMetrics as primary backend, with MRTG as fallback
GRAPHER_BACKENDS=victoriametrics|mrtg

# VictoriaMetrics HTTP API endpoint
GRAPHER_BACKEND_VM_URL=http://your-vm-host:8428
```

Multiple backends are supported via pipe (`|`) separation. IXP Manager will try each in order and use the first one that can handle the requested graph.

### 4. Add the uPlot Renderer

#### a. Register the render style

In `app/Services/Grapher/Renderer.php`, add the uPlot box style constant and method (if not already present):

```php
public const BOX_STYLE_UPLOT = 'uplot';

public const BOX_STYLES = [
    self::BOX_STYLE_LEGACY,
    self::BOX_STYLE_UPLOT,
];

public function boxUplot(): string
{
    return $this->box( self::BOX_STYLE_UPLOT );
}
```

#### b. Install the uPlot library

Download [uPlot v1.6.31](https://github.com/leeoniya/uPlot/releases/tag/1.6.31) and place in:

```
public/vendor/uplot/uPlot.min.js
public/vendor/uplot/uPlot.min.css
```

#### c. Create the renderer template

The uPlot renderer template is a Foil `.foil.php` file that receives `$t->graph` and renders an interactive chart. This is where the chart JS, colour schemes, tooltip, and statistics table live.

Place it at **one** of these locations (IXP Manager's skin system checks the skin path first, then falls back to the default):

- `resources/skins/<your-skin>/services/grapher/renderer/box/uplot.foil.php` -- if you use a custom skin (`VIEW_SKIN` in `.env`)
- `resources/views/services/grapher/renderer/box/uplot.foil.php` -- if you use the default skin

The Renderer class resolves the view via `View::make('services.grapher.renderer.box.uplot')`, so the skin override system handles the rest automatically. You only need the file in one location.

### 5. Update Views to Use uPlot

This is the part that **requires skin changes**. The views that render graphs call either `->renderer()->boxLegacy()` (MRTG PNGs) or `->renderer()->boxUplot()` (interactive charts). You need to change those calls in whichever views you want to upgrade.

#### Understanding the IXP Manager Skin System

IXP Manager uses [Foil](https://foilphp.it/) templates with a skin override system:

```
resources/views/                    <-- default views (shipped with IXP Manager)
resources/skins/<your-skin>/        <-- your skin overrides (checked first)
```

When IXP Manager renders a view like `statistics.member`, it checks for `resources/skins/<VIEW_SKIN>/statistics/member.foil.php` first. If found, that's used. Otherwise it falls back to `resources/views/statistics/member.foil.php`.

**You should never edit files in `resources/views/` directly** -- they'll be overwritten on upgrade. Instead, copy the view into your skin directory and modify the copy.

#### Which Views to Override

To get uPlot charts everywhere, you need to override these views and change `boxLegacy()` to `boxUplot()`:

| View Path | Purpose | `boxLegacy()` calls to replace |
|-----------|---------|-------------------------------|
| `statistics/member.foil.php` | Customer "all ports" statistics page | 4 (aggregate, LAG, physical ports) |
| `statistics/member-drilldown.foil.php` | Day/week/month/year drilldown | 1 (inside period loop) |
| `customer/overview-tabs/ports/port.foil.php` | Customer overview ports tab | 2 (LAG aggregate + physical) |
| `customer/overview-tabs/overview.foil.php` | Customer overview aggregate graph | 1 |
| `dashboard/dashboard-tabs/overview.foil.php` | Customer self-service dashboard | 1 |
| `admin/dashboard.foil.php` | Admin dashboard | 1 |
| `statistics/members.foil.php` | All-members comparison page | 1 |
| `statistics/ixp.foil.php` | IXP-wide aggregate statistics | 1 |
| `statistics/infrastructure.foil.php` | Per-infrastructure aggregate | 1 |
| `statistics/switch.foil.php` | Per-switch aggregate | 1 |
| `statistics/location.foil.php` | Per-location (facility) aggregate | 1 |

For each file, the change is mechanical -- find `->renderer()->boxLegacy()` and replace with `->renderer()->boxUplot()`:

```php
<!-- Before -->
<?= $t->grapher->customer( $t->c )->setCategory( $t->category )
    ->setPeriod( $t->period )->renderer()->boxLegacy() ?>

<!-- After -->
<?= $t->grapher->customer( $t->c )->setCategory( $t->category )
    ->setPeriod( $t->period )->renderer()->boxUplot() ?>
```

#### Step-by-Step: Creating a Skin Override

If you don't already have a custom skin, create one:

```bash
# 1. Set your skin name in .env
echo 'VIEW_SKIN=myixp' >> .env

# 2. Create the skin directory
mkdir -p resources/skins/myixp

# 3. Copy the views you want to override
mkdir -p resources/skins/myixp/statistics
mkdir -p resources/skins/myixp/customer/overview-tabs/ports
mkdir -p resources/skins/myixp/services/grapher/renderer/box

cp resources/views/statistics/member.foil.php \
   resources/skins/myixp/statistics/

cp resources/views/statistics/member-drilldown.foil.php \
   resources/skins/myixp/statistics/

cp resources/views/customer/overview-tabs/ports/port.foil.php \
   resources/skins/myixp/customer/overview-tabs/ports/

cp resources/views/customer/overview-tabs/overview.foil.php \
   resources/skins/myixp/customer/overview-tabs/

# 4. Copy the uPlot renderer template
cp resources/views/services/grapher/renderer/box/uplot.foil.php \
   resources/skins/myixp/services/grapher/renderer/box/

# 5. In each copied view, replace boxLegacy() with boxUplot()
```

You only need to override the views you want to change. Any view **not** in your skin directory will continue using the default from `resources/views/`.

#### Incremental Migration

You can migrate views one at a time. For example, override just `statistics/member.foil.php` first to test. The rest of the site continues using MRTG PNGs via `boxLegacy()` until you override those views too. The backend pipe config (`victoriametrics|mrtg`) ensures both renderers have data available.

#### Additional View Improvements (Optional)

Beyond the `boxLegacy()` -> `boxUplot()` swap, you may also want to update layout and headings in your skin overrides. For example, the EdgIX skin makes these additional improvements:

- **Category-aware headings**: "Aggregate Peering Traffic" becomes "Aggregate Peering Errors" when viewing errors
- **Card-based layout**: Each connection wrapped in a Bootstrap card with a grey header for visual separation
- **LAG layout**: LAG aggregate graph full-width, member ports in a 2-column grid below
- **Full-width graphs**: Removed half-width constraints so charts fill available space
- **Form fixes**: Wrapped `<form>` around `<nav>` instead of nesting inside `<ul>` (fixes dropdown submission)

## How It Works

### Backend: Query Flow

1. **Graph creation**: Controller calls `$grapher->physint($pi)->setCategory('bits')->setPeriod('day')`
2. **Backend resolution**: IXP Manager's Grapher service finds VictoriaMetrics as the first capable backend
3. **Metric resolution**: `metricName()` reads the recording rule metric name from config (e.g. `port_bitrate_rx:10s`)
4. **Query building**: `buildQueryForGraph()` maps the graph model to a PromQL query, selecting the appropriate metric tier:
   - Physical Interface → `port_metric{device_interface="switch:port"}` or `svc_metric{...}` for sub-interfaces
   - LAG → `port_metric{device_interface="switch:Port-Channel<n>"}`
   - Sub-interface port → `svc_metric{device_interface="switch:Port-Channel<n>.<index>"}`
   - Customer → splits VIs into parent-port and sub-interface groups, queries each with appropriate metric, combines with PromQL `+`
   - IXP → `sum(port_metric{device=~"all_switches",interface_name=~"Ethernet.*"})` across all switches
   - Infrastructure → `sum(port_metric{device=~"switch1|switch2",interface_name=~"Ethernet.*"})`
   - Switch → `sum(port_metric{device="name",interface_name=~"Ethernet.*"})`
   - Location → same as infrastructure but switches resolved via cabinets
   - Core Bundle → `sum(rate(raw_counter{device_interface=~"member1|member2"}[30s]))*8` (raw counters, not recording rules)
5. **HTTP request**: `queryRange()` hits `/api/v1/query_range` with the query, time range, and step
6. **Data merge**: RX and TX results are merged by timestamp into `[ts, avg_in, avg_out, max_in, max_out]`

### Renderer: uPlot Template

The template receives the graph object and:

1. Extracts data points, category, and period
2. Selects a colour palette based on category (bits/errors/etc.) and graph type (aggregate vs physical)
3. Renders a `<div>` container plus inline `<script>` that:
   - Lazy-loads uPlot JS/CSS on first graph (shared queue for multiple graphs on same page)
   - Creates a responsive chart with SI-unit axis labels
   - Adds a hover tooltip plugin
   - Renders a statistics table (max/avg/current for RX and TX)
   - Handles window resize with debouncing

### Period to PromQL Mapping

| IXP Manager Period | VM Range | Step    | Data Points |
|--------------------|----------|---------|-------------|
| Day                | 24h      | 60s     | ~1,440      |
| Week               | 7d       | 300s    | ~2,016      |
| Month              | 30d      | 1800s   | ~1,440      |
| Year               | 365d     | 86400s  | ~365        |

### Colour Schemes

Each category has distinct RX/TX colour pairs, with separate palettes for aggregate (LAG/customer) and physical port graphs:

| Category    | Aggregate RX/TX      | Physical RX/TX      |
|-------------|----------------------|---------------------|
| Bits        | Purple / Amber       | Green / Blue        |
| Packets     | Sky / Indigo         | Cyan / Violet       |
| Errors      | Red / Orange         | Dark Red / Dark Orange |
| Discards    | Amber / Fuchsia      | Yellow / Purple     |
| Broadcasts  | Teal / Rose          | Dark Teal / Dark Rose |

## DOM (Optical Power) Monitoring

DOM graphs appear automatically below each physical interface traffic graph and below each core bundle member link graph, when the **Bits** category is selected.

### How It Works

The backend queries `dom_rx_power:interface` and `dom_tx_power:interface` using the `device` and `interface_name` labels (not the combined `device_interface` label used by traffic counters). These are gauge values in dBm — no `rate()` is applied.

For breakout optics, the `channel_index` label distinguishes lanes. The DOM chart renders one RX/TX line pair per channel.

### PromQL Queries

```promql
# Single-lane optic
dom_rx_power:interface{device="pe1syd3",interface_name="Ethernet9/2"}
dom_tx_power:interface{device="pe1syd3",interface_name="Ethernet9/2"}

# Multi-lane breakout — returns one series per channel_index
dom_rx_power:interface{device="pe1syd3",interface_name="Ethernet25/1"}
```

### Display

- **Chart**: 180px uPlot time series, Y-axis in dBm
- **Stats table**: Min, Avg, Max, Current for each RX/TX channel
- Values of -30 dBm are filtered out (sentinel for no-light / SFP not present)
- Multi-channel optics show separate colour-coded lines per lane

### Prerequisite

These metrics typically require a recording rule or relabelling step to map the OpenConfig transceiver subscription's `component_name` (e.g. `Ethernet1`) to the actual `interface_name` (e.g. `Ethernet1/1`). The raw OpenConfig transceiver metrics use `component_name` which does not match the interface hierarchy directly.

## Interface Status Overlay

Physical interface traffic graphs automatically show a red semi-transparent background overlay during periods when the interface was operationally down.

### How It Works

The uPlot renderer detects `PhysicalInterface` graphs and queries `openconfig_interfaces_oper_status{device_interface="..."}` as a time series. A uPlot `draw` hook plugin paints red rectangles (`rgba(239, 68, 68, 0.12)`) over time ranges where the status value is not 1 (UP).

This provides immediate visual correlation between interface flaps and traffic drops.

## Capacity Utilization Badges

Colour-coded utilization percentage badges appear in port and LAG card headers when viewing the **Bits** category.

### Calculation

- **Single port**: `max(current_rx, current_tx) / (port_speed_mbps × 1,000,000) × 100`
- **LAG**: `max(current_rx, current_tx) / (sum_of_member_port_speeds × 1,000,000) × 100`
- **Core Bundle**: `max(current_rx, current_tx) / (link_count × link_speed × 1,000,000) × 100`

### Colour Coding

| Utilization | Badge Colour |
|-------------|-------------|
| < 50%       | Green       |
| 50–80%      | Yellow      |
| > 80%       | Red         |

The port speed comes from the `PhysicalInterface.speed` database column (in Mbps). The current rate comes from the traffic graph statistics (already computed from the VM query).

## Top-N Ports Dashboard

An admin-only page (`/statistics/top-n`) showing the busiest ports by current traffic rate. Accessible via the "Top Ports" link in the Statistics dropdown menu (visible to superusers only).

### How It Works

Uses a PromQL `topk()` instant query on the recording rule gauge to find the highest-traffic interfaces:

```promql
topk(20, port_bitrate_rx:10s{interface_name=~"Ethernet.*"})
```

Each result is cross-referenced with the IXP Manager database to resolve the customer, port details, location, and speed. The `resolveDeviceInterface()` method splits the `device_interface` label, looks up the `Switcher` by name, finds the `SwitchPort`, then follows the relationship chain to `PhysicalInterface` → `VirtualInterface` → `Customer`.

### Features

- Direction filter: RX (In) or TX (Out)
- Limit selector: Top 10, 20, 50, or 100
- Sortable table with columns: Rank, Customer (linked), Port (linked to drilldown), Location, Current Rate, Port Speed, Utilization %
- Ports not mapped to a customer in IXP Manager (e.g. unprovisioned or core ports) show as "-"

## Configuration Reference

### Environment Variables

#### Core Grapher Backend

| Variable | Default | Description |
|----------|---------|-------------|
| `GRAPHER_BACKENDS` | `dummy` | Pipe-separated list of backend names |
| `GRAPHER_BACKEND_VM_URL` | `http://localhost:8428` | VictoriaMetrics API endpoint |
| `GRAPHER_CACHE_ENABLED` | `true` | Enable graph data caching |
| `GRAPHER_CACHE_LIFETIME` | `5` | Cache TTL in minutes |

#### Recording Rule Metric Names (optional overrides)

These only need to be set if your recording rules use different names than the defaults.

| Variable | Default | Description |
|----------|---------|-------------|
| `GRAPHER_VM_METRIC_BITS_RX` | `port_bitrate_rx:10s` | RX bits/s recording rule |
| `GRAPHER_VM_METRIC_BITS_TX` | `port_bitrate_tx:10s` | TX bits/s recording rule |
| `GRAPHER_VM_METRIC_PKTS_RX` | `port_unicast_pps_rx:10s` | RX packets/s recording rule |
| `GRAPHER_VM_METRIC_PKTS_TX` | `port_unicast_pps_tx:10s` | TX packets/s recording rule |
| `GRAPHER_VM_METRIC_ERRS_RX` | `port_errors_pps_rx:10s` | RX errors/s recording rule |
| `GRAPHER_VM_METRIC_ERRS_TX` | `port_errors_pps_tx:10s` | TX errors/s recording rule |
| `GRAPHER_VM_METRIC_DISC_RX` | `port_discards_pps_rx:10s` | RX discards/s recording rule |
| `GRAPHER_VM_METRIC_DISC_TX` | `port_discards_pps_tx:10s` | TX discards/s recording rule |
| `GRAPHER_VM_METRIC_BCAST_RX` | `port_broadcasts_pps_rx:10s` | RX broadcasts/s recording rule |
| `GRAPHER_VM_METRIC_BCAST_TX` | `port_broadcasts_pps_tx:10s` | TX broadcasts/s recording rule |
| `GRAPHER_VM_SUBINT_BITS_RX` | `svc_bitrate_rx:10s` | Sub-interface RX bits/s recording rule |
| `GRAPHER_VM_SUBINT_BITS_TX` | `svc_bitrate_tx:10s` | Sub-interface TX bits/s recording rule |

### Supported Graph Types

The VictoriaMetrics backend supports:

- **Physical Interface** -- individual switch ports (peer and core)
- **Virtual Interface** -- LAGs / Port-Channels
- **Customer** -- aggregate across all customer ports
- **IXP** -- IXP-wide aggregate (all Ethernet interfaces across all switches)
- **Infrastructure** -- per-infrastructure aggregate (all switches in an infrastructure)
- **Switch** -- per-switch aggregate (all Ethernet interfaces on a switch)
- **Location** -- per-location/facility aggregate (all switches in cabinets at a location)
- **Core Bundle** -- inter-switch link aggregates with per-member breakdown (all five categories)

Not yet supported (still served by MRTG/other backends if configured):

- **VLAN** -- per-VLAN sub-interface graphs (requires `openconfig_subinterfaces` metrics)
- **P2P** -- peer-to-peer traffic (requires sflow)

## Pseudowire Traffic Graphs

The `edgeix/ixpm-pseudowire` module includes standalone traffic graphs for pseudowire circuits. These are **independent of the core Grapher framework** because pseudowire circuits use sub-interface metrics at a different level in the OpenConfig hierarchy.

### How It Works

Each pseudowire circuit has two endpoints (A-End, Z-End). Each endpoint maps to a sub-interface identified by `switch_name:port_name.subif_vlan` (e.g. `pe2syd1:Port-Channel6.1020`). By default, the `PwTrafficService` queries pre-computed recording rule gauges:

```promql
svc_bitrate_rx:10s{device_interface="pe2syd1:Port-Channel6.1020"}
```

For deployments without recording rules, set `PW_METRICS_USE_RECORDING_RULES=false` to fall back to raw counter queries with `rate()`.

The admin circuit detail page fetches data via AJAX (`GET /pseudowire/admin/circuits/{id}/traffic?period=day`) and renders uPlot charts for both ends.

### Configuration

All metrics settings live in `config/pseudowire.php` under the `metrics` key. This makes the module portable — other IXPs can adapt to their Prometheus/VM deployment by overriding these values.

| `.env` Variable | Default | Description |
|-----------------|---------|-------------|
| `PW_METRICS_URL` | *(none — feature disabled)* | VictoriaMetrics / Prometheus base URL |
| `PW_METRICS_RX` | `svc_bitrate_rx:10s` | RX metric name (recording rule gauge) |
| `PW_METRICS_TX` | `svc_bitrate_tx:10s` | TX metric name (recording rule gauge) |
| `PW_METRICS_USE_RECORDING_RULES` | `true` | `true` = query as gauge; `false` = wrap in `rate()*multiplier` |
| `PW_METRICS_LABEL` | `device_interface` | Label name for sub-interface matching |
| `PW_METRICS_LABEL_FORMAT` | `{switch_name}:{port_name}.{subif_vlan}` | Label value pattern with placeholders |
| `PW_METRICS_RATE_INTERVAL` | `30s` | `rate()` window (only when `USE_RECORDING_RULES=false`) |
| `PW_METRICS_MULTIPLIER` | `8` | Post-rate multiplier (only when `USE_RECORDING_RULES=false`) |

**Feature gate**: If `PW_METRICS_URL` is not set, the traffic graph card does not render.

### Label Resolution

The `PwTrafficService::buildLabel()` method derives the `device_interface` label from IXP Manager model relationships:

```
PwCircuit → VirtualInterface → PhysicalInterface → SwitchPort → Switcher
                              ↓
                LAG? → Port-Channel{channelgroup}
                Single? → switchPort->name
                              ↓
          "{switch_name}:{port_name}.{subif_vlan}"
```

This uses the same LAG detection logic as the core VictoriaMetrics backend (`VictoriaMetrics.php:192-196`).

### Period Mapping

| Period | Range | Step | Approx Points |
|--------|-------|------|---------------|
| Hour   | 1h    | 15s  | ~240          |
| Day    | 24h   | 60s  | ~1,440        |
| Week   | 7d    | 300s | ~2,016        |
| Month  | 30d   | 1800s| ~1,440        |
| Year   | 365d  | 86400s| ~365         |

### Key Differences from Core Grapher

| Aspect | Core Grapher | Pseudowire Module |
|--------|-------------|-------------------|
| Metrics | `port_bitrate_*` (ports) + `svc_bitrate_*` (sub-ints) + raw counter fallback | `svc_bitrate_*` recording rules with raw counter auto-fallback |
| Query building | `buildQueryForGraph()` in Backend class | `PwTrafficService::buildQuery()` |
| Data loading | Server-side (PHP, baked into Foil template) | Client-side (AJAX fetch, JS rendering) |
| Config | `config/grapher.php` → `backends.victoriametrics.metrics` + `subinterface_metrics` | `config/pseudowire.php` → `metrics` |
| Label format | `switch:port` (physical) or `switch:port.index` (sub-int) | `switch:port.subif_vlan` |
| Raw counter fallback | Yes — core links, sub-interfaces without recording rules | Yes — PW sub-interfaces not in `ixpmanager_svc` enrichment |

## Troubleshooting

### No data displayed

1. Check VictoriaMetrics is reachable and the recording rule metric exists:
   ```bash
   curl -G 'http://your-vm:8428/api/v1/query' \
     --data-urlencode 'query=port_bitrate_rx:10s{device_interface="pe1syd3:Ethernet9/2"}'
   ```

2. Verify the `device_interface` label format matches. Check what labels exist in VM:
   ```bash
   curl 'http://your-vm:8428/api/v1/label/device_interface/values' | grep pe1syd3
   ```

3. Check all available recording rule metrics for a port:
   ```bash
   curl -G 'http://your-vm:8428/api/v1/label/__name__/values' \
     --data-urlencode 'match[]={device_interface="pe1syd3:Ethernet9/2"}'
   ```

4. Check Laravel logs for query debug output:
   ```bash
   tail -f storage/logs/laravel.log | grep VictoriaMetrics
   ```

### Category/period dropdowns not working

IXP Manager's `StatisticsRequest` class declares public properties (`$period`, `$category`) that shadow Laravel's `__get` magic method. If dropdowns don't persist selections, ensure the controller uses `$request->input('period')` instead of `$request->period`.

### OPcache

If running with aggressive OPcache (e.g. `validate_timestamps=0`), restart your web server after deploying PHP changes.

## Status and Roadmap

### Working
- Physical interface graphs (all categories, all periods) — peer and core ports
- Virtual interface / LAG graphs (aggregate + member ports)
- Customer aggregate graphs
- IXP-wide aggregate graphs
- Per-infrastructure aggregate graphs
- Per-switch aggregate graphs
- Per-location (facility) aggregate graphs
- Core Bundle / Trunk graphs — aggregate + per-member link breakdown (all five categories)
- Admin dashboard aggregate graphs (IXP + Infrastructure)
- Interactive uPlot charts with tooltips, zoom, responsive layout
- Category-aware headings and colour schemes
- Statistics tables (max, average, current)
- Three-tier metric system: port recording rules → sub-interface recording rules → raw counter fallback
- All configurable metric names via `.env` or `config/grapher.php`
- DOM (optical power) graphs — RX/TX dBm time series on physical ports and core link members
- Interface status overlay — red background shading on traffic graphs during down periods
- Capacity utilization badges — colour-coded % on port/LAG/core bundle headers
- Top-N ports dashboard — admin-only page showing busiest ports by current traffic

### Pseudowire Circuit Traffic Graphs (via `edgeix/ixpm-pseudowire`)
- Per-circuit sub-interface traffic graphs on admin circuit detail page
- Standalone implementation — does **not** use the core Grapher Backend; queries VM directly via `PwTrafficService`
- Uses recording rule gauges by default (`svc_bitrate_rx:10s`), with raw counter fallback
- Fully configurable via `config/pseudowire.php` `metrics` key (metric names, label format, VM URL, recording rule toggle)
- See [Pseudowire Traffic Graphs](#pseudowire-traffic-graphs) section below

### Planned
- VLAN graphs (sflow-based, separate data pipeline)
- Sflow P2P replacement
- Exportable graph images (server-side PNG via headless rendering)

## License

Same license as IXP Manager (GPL v2.0).

## Credits

Developed by [EdgeIX](https://edgeix.net/) for production use at the EdgeIX Internet Exchange.
