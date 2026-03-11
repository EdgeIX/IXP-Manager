# VictoriaMetrics Grapher Backend for IXP Manager

A drop-in replacement for the MRTG/RRD grapher backend in [IXP Manager](https://www.ixpmanager.org/), powered by [VictoriaMetrics](https://victoriametrics.com/) and interactive [uPlot](https://github.com/leeoniya/uPlot) charts.

Replaces legacy server-rendered MRTG PNG graphs with client-side interactive time-series charts, backed by streaming telemetry data stored in VictoriaMetrics (or any Prometheus-compatible TSDB).

## Features

- **Interactive charts** -- zoom, hover tooltips, responsive resizing (uPlot, ~50KB JS)
- **All five graph categories** -- Bits, Packets, Errors, Discards, Broadcasts
- **Eight graph scopes** -- Physical Interface, Virtual Interface (LAG/Port-Channel), Customer Aggregate, IXP-wide, Infrastructure, Switch, Location, Core Bundle
- **Four time periods** -- Day, Week, Month, Year
- **Category-aware colour schemes** -- distinct palettes for each metric type, with separate aggregate/physical variants
- **No recording rules or config files** -- queries raw OpenConfig counters directly; no cron jobs, no RRD files, no MRTG config
- **Pluggable** -- uses IXP Manager's existing `Grapher\Backend` contract; can run alongside MRTG as a fallback

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
2. **Query**: The backend builds PromQL `rate()` queries on raw OpenConfig counters and sends `query_range` requests to the VictoriaMetrics HTTP API
3. **Render**: The uPlot Foil template renders interactive JavaScript charts client-side

No recording rules or pre-computed metrics are required. The backend queries raw OpenConfig counters directly (e.g. `openconfig_interfaces_in_octets`) and wraps them with `rate(...[30s])` to derive per-second rates.

## Prerequisites

- IXP Manager v6.x+ (PHP 8.1+, Laravel 9+)
- VictoriaMetrics (or Prometheus) with port traffic metrics
- Streaming telemetry collector (gNMIC recommended) feeding metrics into VM
- uPlot v1.6.31 vendored at `public/vendor/uplot/`

### Required Raw OpenConfig Counters

The backend queries raw OpenConfig interface counters directly using `rate()`. No recording rules are needed. The following counters must be present with a `device_interface` label in the format `switch_name:port_name` (e.g. `pe1syd3:Ethernet9/2`):

| Category    | RX Counter                                    | TX Counter                                     | Multiplier |
|-------------|-----------------------------------------------|------------------------------------------------|------------|
| Bits        | `openconfig_interfaces_in_octets`             | `openconfig_interfaces_out_octets`              | ×8         |
| Packets     | `openconfig_interfaces_in_unicast_pkts`       | `openconfig_interfaces_out_unicast_pkts`        | ×1         |
| Errors      | `openconfig_interfaces_in_errors`             | `openconfig_interfaces_out_errors`              | ×1         |
| Discards    | `openconfig_interfaces_in_discards`           | `openconfig_interfaces_out_discards`            | ×1         |
| Broadcasts  | `openconfig_interfaces_in_broadcast_pkts`     | `openconfig_interfaces_out_broadcast_pkts`      | ×1         |

Values are raw monotonically-increasing counters. The backend applies `rate(...[30s])` to convert to per-second rates, then multiplies by 8 for octets→bits conversion where applicable.

### Label Format

The recording rules must include these labels:

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
sum(rate(openconfig_interfaces_in_octets{device_interface=~"pe1syd3:Port-Channel42|pe2syd3:Ethernet5/1"}[30s]))*8
```

#### Aggregate Graphs (IXP, Infrastructure, Switch, Location)

These use the `device` and `interface_name` labels, summing `rate()` across all Ethernet interfaces on the relevant switches. The `interface_name=~"Ethernet.*"` filter avoids double-counting LAG members alongside Port-Channel aggregates (Port-Channel interfaces don't match `Ethernet.*`):

| Graph Type     | PromQL Pattern                                                           |
|----------------|--------------------------------------------------------------------------|
| IXP-wide       | `sum(rate(counter{interface_name=~"Ethernet.*"}[30s]))*multiplier`       |
| Infrastructure | `sum(rate(counter{device=~"sw1\|sw2",interface_name=~"Ethernet.*"}[30s]))*multiplier` |
| Switch         | `sum(rate(counter{device="name",interface_name=~"Ethernet.*"}[30s]))*multiplier` |
| Location       | `sum(rate(counter{device=~"sw1\|sw2",interface_name=~"Ethernet.*"}[30s]))*multiplier` |

#### Core Bundle Graphs (inter-switch links)

Core bundle graphs sum `rate()` across the member port interfaces for a given side (A or B):

| Graph Type     | PromQL Pattern                                                           |
|----------------|--------------------------------------------------------------------------|
| Core Bundle (aggregate) | `sum(rate(counter{device_interface=~"pe1:Eth25/1\|pe1:Eth26/1"}[30s]))*multiplier` |
| Core Bundle (single link) | `rate(counter{device_interface="pe1:Ethernet25/1"}[30s])*multiplier` |
| Individual member port | `rate(counter{device_interface="pe1:Ethernet25/1"}[30s])*multiplier` |

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
3. **Interface mapping**: `buildQueryForGraph()` maps the IXP Manager graph model to a PromQL `rate()` query on raw OpenConfig counters:
   - Physical Interface → `rate(counter{device_interface="switch:port"}[30s])*multiplier`
   - LAG → `rate(counter{device_interface="switch:Port-Channel<n>"}[30s])*multiplier`
   - Customer → `sum(rate(counter{device_interface=~"regex"}[30s]))*multiplier` across all customer ports
   - IXP → `sum(rate(counter{interface_name=~"Ethernet.*"}[30s]))*multiplier` across all switches
   - Infrastructure → `sum(rate(counter{device=~"switch1|switch2",interface_name=~"Ethernet.*"}[30s]))*multiplier`
   - Switch → `sum(rate(counter{device="name",interface_name=~"Ethernet.*"}[30s]))*multiplier`
   - Location → same as infrastructure but switches resolved via cabinets
   - Core Bundle → `sum(rate(counter{device_interface=~"member1|member2"}[30s]))*multiplier` across bundle member ports
4. **HTTP request**: `queryRange()` hits `/api/v1/query_range` with the query, time range, and step
5. **Data merge**: RX and TX results are merged by timestamp into `[ts, avg_in, avg_out, max_in, max_out]`

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

## Configuration Reference

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GRAPHER_BACKENDS` | `dummy` | Pipe-separated list of backend names |
| `GRAPHER_BACKEND_VM_URL` | `http://localhost:8428` | VictoriaMetrics API endpoint |
| `GRAPHER_CACHE_ENABLED` | `true` | Enable graph data caching |
| `GRAPHER_CACHE_LIFETIME` | `5` | Cache TTL in minutes |

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

The `edgeix/ixpm-pseudowire` module includes standalone traffic graphs for pseudowire circuits. These are **independent of the core Grapher framework** because pseudowire circuits use sub-interface metrics that require raw `rate()` queries on OpenConfig counters, not the pre-computed recording rules used by the main grapher.

### How It Works

Each pseudowire circuit has two endpoints (A-End, Z-End). Each endpoint maps to a sub-interface identified by `switch_name:port_name.subif_vlan` (e.g. `pe2syd1:Port-Channel6.1020`). The `PwTrafficService` builds PromQL queries like:

```promql
rate(openconfig_subinterfaces_in_octets{device_interface="pe2syd1:Port-Channel6.1020"}[30s])*8
```

The admin circuit detail page fetches data via AJAX (`GET /pseudowire/admin/circuits/{id}/traffic?period=day`) and renders uPlot charts for both ends.

### Configuration

All metrics settings live in `config/pseudowire.php` under the `metrics` key. This makes the module portable — other IXPs can adapt to their Prometheus/VM deployment by overriding these values.

| `.env` Variable | Default | Description |
|-----------------|---------|-------------|
| `PW_METRICS_URL` | *(none — feature disabled)* | VictoriaMetrics / Prometheus base URL |
| `PW_METRICS_RX` | `openconfig_subinterfaces_in_octets` | RX counter metric name |
| `PW_METRICS_TX` | `openconfig_subinterfaces_out_octets` | TX counter metric name |
| `PW_METRICS_LABEL` | `device_interface` | Label name for sub-interface matching |
| `PW_METRICS_LABEL_FORMAT` | `{switch_name}:{port_name}.{subif_vlan}` | Label value pattern with placeholders |
| `PW_METRICS_RATE_INTERVAL` | `30s` | `rate()` window for PromQL |
| `PW_METRICS_MULTIPLIER` | `8` | Octets-to-bits multiplier |

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
| Metrics | Raw OpenConfig counters with `rate()` (interface-level) | Raw OpenConfig counters with `rate()` (sub-interface-level) |
| Query building | `buildQueryForGraph()` in Backend class | `PwTrafficService::buildQuery()` |
| Data loading | Server-side (PHP, baked into Foil template) | Client-side (AJAX fetch, JS rendering) |
| Config | `config/grapher.php` | `config/pseudowire.php` → `metrics` |
| Label format | `switch:port` | `switch:port.subif_vlan` |

## Troubleshooting

### No data displayed

1. Check VictoriaMetrics is reachable from the IXP Manager host:
   ```bash
   curl -G 'http://your-vm:8428/api/v1/query' \
     --data-urlencode 'query=rate(openconfig_interfaces_in_octets{device_interface="pe1syd3:Ethernet9/2"}[30s])*8'
   ```

2. Verify the `device_interface` label format matches. Check what labels exist in VM:
   ```bash
   curl 'http://your-vm:8428/api/v1/label/device_interface/values' | grep pe1syd3
   ```

3. Check all available raw metrics for a port:
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
- All queries use raw OpenConfig counters with `rate()` — no recording rules required

### Pseudowire Circuit Traffic Graphs (via `edgeix/ixpm-pseudowire`)
- Per-circuit sub-interface traffic graphs on admin circuit detail page
- Standalone implementation — does **not** use the core Grapher Backend; queries VM directly via `PwTrafficService`
- Uses raw `rate()` on OpenConfig sub-interface counters
- Fully configurable via `config/pseudowire.php` `metrics` key (metric names, label format, VM URL, rate interval)
- See [Pseudowire Traffic Graphs](#pseudowire-traffic-graphs) section below

### Planned
- VLAN graphs (sflow-based, separate data pipeline)
- DOM optics monitoring panel
- Sflow P2P replacement
- Exportable graph images (server-side PNG via headless rendering)

## License

Same license as IXP Manager (GPL v2.0).

## Credits

Developed by [EdgeIX](https://edgeix.net/) for production use at the EdgeIX Internet Exchange.
