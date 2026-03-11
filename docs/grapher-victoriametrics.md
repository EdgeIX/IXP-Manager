# VictoriaMetrics Grapher Backend for IXP Manager

A drop-in replacement for the MRTG/RRD grapher backend in [IXP Manager](https://www.ixpmanager.org/), powered by [VictoriaMetrics](https://victoriametrics.com/) and interactive [uPlot](https://github.com/leeoniya/uPlot) charts.

Replaces legacy server-rendered MRTG PNG graphs with client-side interactive time-series charts, backed by streaming telemetry data stored in VictoriaMetrics (or any Prometheus-compatible TSDB).

## Features

- **Interactive charts** -- zoom, hover tooltips, responsive resizing (uPlot, ~50KB JS)
- **All five graph categories** -- Bits, Packets, Errors, Discards, Broadcasts
- **Three graph scopes** -- Physical Interface, Virtual Interface (LAG/Port-Channel), Customer Aggregate
- **Four time periods** -- Day, Week, Month, Year
- **Category-aware colour schemes** -- distinct palettes for each metric type, with separate aggregate/physical variants
- **No config file generation** -- no cron jobs, no RRD files, no MRTG config; just point at your TSDB
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
2. **Recording Rules**: Pre-computed enriched metrics at 10s resolution (e.g. `port_bitrate_rx:10s`) with labels like `device_interface`, `customer`, `asn`, `bundle_parent`
3. **Query**: The backend builds PromQL `query_range` requests against the VictoriaMetrics HTTP API
4. **Render**: The uPlot Foil template renders interactive JavaScript charts client-side

## Prerequisites

- IXP Manager v6.x+ (PHP 8.1+, Laravel 9+)
- VictoriaMetrics (or Prometheus) with port traffic metrics
- Streaming telemetry collector (gNMIC recommended) feeding metrics into VM
- uPlot v1.6.31 vendored at `public/vendor/uplot/`

### Required Recording Rules

The backend expects these recording rule metric names with a `device_interface` label in the format `switch_name:port_name` (e.g. `pe1syd3:Ethernet9/2`):

| Category    | RX Metric                    | TX Metric                    |
|-------------|------------------------------|------------------------------|
| Bits        | `port_bitrate_rx:10s`        | `port_bitrate_tx:10s`        |
| Packets     | `port_unicast_pps_rx:10s`    | `port_unicast_pps_tx:10s`    |
| Errors      | `port_errors_pps_rx:10s`     | `port_errors_pps_tx:10s`     |
| Discards    | `port_discards_pps_rx:10s`   | `port_discards_pps_tx:10s`   |
| Broadcasts  | `port_broadcast_pps_rx:10s`  | `port_broadcast_pps_tx:10s`  |

Values should be **rates** (bits per second or packets per second), not raw counters.

### device_interface Label Format

The `device_interface` label must match what IXP Manager generates:

| Graph Type         | Label Format                          | Example                        |
|--------------------|---------------------------------------|--------------------------------|
| Physical Interface | `switch_name:port_name`               | `pe1syd3:Ethernet9/2`          |
| LAG (Port-Channel) | `switch_name:Port-Channel<group>`     | `pe1syd3:Port-Channel42`       |
| Single-member VI   | `switch_name:port_name`               | `pe1syd3:Ethernet8/3`          |

**Important**: `switch_name` comes from the Switch model's `name` field in IXP Manager (not the `hostname` field, which is typically the management IP/FQDN).

For **Customer Aggregate** graphs, the backend queries all of a customer's interfaces using PromQL regex alternation:
```promql
sum(port_bitrate_rx:10s{device_interface=~"pe1syd3:Port-Channel42|pe2syd3:Ethernet5/1"})
```

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
3. **Interface mapping**: `resolveDeviceInterface()` maps the IXP Manager model to a `device_interface` label:
   - Physical Interface -> `switch_name:port_name`
   - LAG -> `switch_name:Port-Channel<channelgroup>`
   - Customer -> array of all interface labels
4. **PromQL query**: `buildQuery()` constructs the query. Single interfaces use exact match; customer aggregates use `sum()` with regex
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

## Configuration Reference

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GRAPHER_BACKENDS` | `dummy` | Pipe-separated list of backend names |
| `GRAPHER_BACKEND_VM_URL` | `http://localhost:8428` | VictoriaMetrics API endpoint |
| `GRAPHER_CACHE_ENABLED` | `true` | Enable graph data caching |
| `GRAPHER_CACHE_LIFETIME` | `5` | Cache TTL in minutes |

### Supported Graph Types

The VictoriaMetrics backend currently supports:

- **Physical Interface** -- individual switch ports
- **Virtual Interface** -- LAGs / Port-Channels
- **Customer** -- aggregate across all customer ports

Not yet supported (still served by MRTG/other backends if configured):

- IXP aggregate
- Infrastructure
- Switch
- Trunk
- VLAN
- P2P (sflow)

## Troubleshooting

### No data displayed

1. Check VictoriaMetrics is reachable from the IXP Manager host:
   ```bash
   curl "http://your-vm:8428/api/v1/query?query=port_bitrate_rx:10s"
   ```

2. Verify the `device_interface` label format matches. Check what's in VM:
   ```bash
   curl 'http://your-vm:8428/api/v1/series?match[]=port_bitrate_rx:10s'
   ```

3. Check Laravel logs for query debug output:
   ```bash
   tail -f storage/logs/laravel.log | grep VictoriaMetrics
   ```

### Category/period dropdowns not working

IXP Manager's `StatisticsRequest` class declares public properties (`$period`, `$category`) that shadow Laravel's `__get` magic method. If dropdowns don't persist selections, ensure the controller uses `$request->input('period')` instead of `$request->period`.

### OPcache

If running with aggressive OPcache (e.g. `validate_timestamps=0`), restart your web server after deploying PHP changes.

## Status and Roadmap

### Working
- Physical interface graphs (all categories, all periods)
- Virtual interface / LAG graphs (aggregate + member ports)
- Customer aggregate graphs
- Interactive uPlot charts with tooltips, zoom, responsive layout
- Category-aware headings and colour schemes
- Statistics tables (max, average, current)

### Planned
- IXP/Infrastructure/Switch/VLAN aggregate graphs
- Sub-interface graphs (per-VLAN, per-pseudowire)
- DOM optics monitoring panel
- Sflow P2P replacement
- Exportable graph images (server-side PNG via headless rendering)

## License

Same license as IXP Manager (GPL v2.0).

## Credits

Developed by [EdgeIX](https://edgeix.net/) for production use at the EdgeIX Internet Exchange.
