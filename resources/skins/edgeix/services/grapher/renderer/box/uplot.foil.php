<?php
    /**
     * EdgIX skin: uPlot graph renderer.
     *
     * Renders interactive time-series charts using uPlot.
     * Replaces the legacy MRTG PNG-based renderer with client-side charts.
     *
     * Expected variables:
     *   $t->graph  — Graph object with data() method returning
     *                [ [timestamp, avg_in, avg_out, max_in, max_out], ... ]
     */

    $graph    = $t->graph;
    $data     = $graph->data();
    $category = $graph->category();
    $period   = $graph->period();
    $graphId  = 'uplot-' . md5( $graph->identifier() . $category . $period . uniqid() );

    // Auto-fetch interface status overlay for physical interface graphs
    $statusData = null;
    if( $graph instanceof \IXP\Services\Grapher\Graph\PhysicalInterface ) {
        try {
            $vmBackend = app( \IXP\Services\Grapher\Backend\VictoriaMetrics::class );
            $statusData = $vmBackend->statusData( $graph->physicalInterface(), $period );
        } catch( \Throwable $e ) {
            // Silently skip if status data unavailable
        }
    }

    // Build uPlot data format: [ timestamps[], series1[], series2[], ... ]
    $timestamps = [];
    $rxValues   = [];
    $txValues   = [];

    foreach( $data as $point ) {
        $timestamps[] = $point[0];
        $rxValues[]   = $point[1];
        $txValues[]   = $point[2];
    }

    // Calculate statistics
    $stats = $graph->statistics();

    // Category labels and units
    $isBits = $category === 'bits';
    $unitSuffix = match( $category ) {
        'bits'       => 'bps',
        'packets'    => 'pps',
        'errors'     => 'pps',
        'discards'   => 'pps',
        'broadcasts' => 'pps',
        default      => 'pps',
    };

    // Colour scheme: category-aware + aggregate/physical distinction
    $isAggregate = ( $graph instanceof \IXP\Services\Grapher\Graph\VirtualInterface )
                || ( $graph instanceof \IXP\Services\Grapher\Graph\Customer )
                || ( $graph instanceof \IXP\Services\Grapher\Graph\IXP )
                || ( $graph instanceof \IXP\Services\Grapher\Graph\Infrastructure )
                || ( $graph instanceof \IXP\Services\Grapher\Graph\Switcher )
                || ( $graph instanceof \IXP\Services\Grapher\Graph\Location );

    // Category-specific colour palettes
    // Each category has [aggregateRx, aggregateTx, physicalRx, physicalTx]
    $colourMap = [
        'bits' => [
            'agg'  => [ '#8b5cf6', 'rgba(139, 92, 246, 0.25)', '#f59e0b', 'rgba(245, 158, 11, 0.25)' ],  // purple / amber
            'phys' => [ '#22c55e', 'rgba(34, 197, 94, 0.25)',   '#3b82f6', 'rgba(59, 130, 246, 0.25)' ],  // green / blue
        ],
        'packets' => [
            'agg'  => [ '#0ea5e9', 'rgba(14, 165, 233, 0.25)',  '#6366f1', 'rgba(99, 102, 241, 0.25)' ],  // sky / indigo
            'phys' => [ '#06b6d4', 'rgba(6, 182, 212, 0.25)',   '#818cf8', 'rgba(129, 140, 248, 0.25)' ], // cyan / violet
        ],
        'errors' => [
            'agg'  => [ '#ef4444', 'rgba(239, 68, 68, 0.25)',   '#f97316', 'rgba(249, 115, 22, 0.25)' ],  // red / orange
            'phys' => [ '#dc2626', 'rgba(220, 38, 38, 0.25)',   '#ea580c', 'rgba(234, 88, 12, 0.25)' ],   // darker red / darker orange
        ],
        'discards' => [
            'agg'  => [ '#f59e0b', 'rgba(245, 158, 11, 0.25)',  '#d946ef', 'rgba(217, 70, 239, 0.25)' ],  // amber / fuchsia
            'phys' => [ '#eab308', 'rgba(234, 179, 8, 0.25)',   '#c026d3', 'rgba(192, 38, 211, 0.25)' ],  // yellow / purple
        ],
        'broadcasts' => [
            'agg'  => [ '#14b8a6', 'rgba(20, 184, 166, 0.25)',  '#f43f5e', 'rgba(244, 63, 94, 0.25)' ],   // teal / rose
            'phys' => [ '#0d9488', 'rgba(13, 148, 136, 0.25)',  '#e11d48', 'rgba(225, 29, 72, 0.25)' ],   // darker teal / darker rose
        ],
    ];

    $palette = $colourMap[ $category ] ?? $colourMap['bits'];
    $scheme  = $isAggregate ? $palette['agg'] : $palette['phys'];

    $rxStroke = $scheme[0];
    $rxFill   = $scheme[1];
    $txStroke = $scheme[2];
    $txFill   = $scheme[3];
?>

<?php if( empty( $data ) ): ?>
    <div class="alert alert-info">
        No data available for this graph.
    </div>
<?php else: ?>

<div class="uplot-graph-container" style="margin-bottom: 0.5rem; overflow: hidden;">
    <div id="<?= $graphId ?>" style="width: 100%; min-width: 0;"></div>

    <table class="table table-sm table-borderless mt-1 mb-0" style="font-size: 0.85rem; max-width: 450px;">
        <thead>
            <tr>
                <th></th>
                <th class="text-right">Max</th>
                <th class="text-right">Average</th>
                <th class="text-right">Current</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span style="color: <?= $rxStroke ?>; font-weight: bold;">RX (In)</span></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->maxIn(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->averageIn(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->curIn(), $category ) ?></td>
            </tr>
            <tr>
                <td><span style="color: <?= $txStroke ?>; font-weight: bold;">TX (Out)</span></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->maxOut(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->averageOut(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->curOut(), $category ) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<script>
(function() {
    var graphId    = <?= json_encode( $graphId ) ?>;
    var isBits     = <?= json_encode( $isBits ) ?>;
    var unitSuffix = <?= json_encode( $unitSuffix ) ?>;

    var rxStroke = <?= json_encode( $rxStroke ) ?>;
    var rxFill   = <?= json_encode( $rxFill ) ?>;
    var txStroke = <?= json_encode( $txStroke ) ?>;
    var txFill   = <?= json_encode( $txFill ) ?>;

    var timestamps = <?= json_encode( $timestamps ) ?>;
    var rxValues   = <?= json_encode( $rxValues ) ?>;
    var txValues   = <?= json_encode( $txValues ) ?>;
    var statusData = <?= json_encode( $statusData ) ?>;

    function fmtSI(val, suffix) {
        if (val == null || isNaN(val)) return '';
        var neg = val < 0 ? '-' : '';
        var abs = Math.abs(val);
        if (abs >= 1e12) return neg + (abs / 1e12).toFixed(1) + ' T' + suffix;
        if (abs >= 1e9)  return neg + (abs / 1e9).toFixed(1)  + ' G' + suffix;
        if (abs >= 1e6)  return neg + (abs / 1e6).toFixed(1)  + ' M' + suffix;
        if (abs >= 1e3)  return neg + (abs / 1e3).toFixed(1)  + ' K' + suffix;
        if (abs > 0)     return neg + abs.toFixed(0) + ' ' + suffix;
        return '0';
    }

    function fmtAxis(val) {
        return fmtSI(val, unitSuffix);
    }

    function fmtTooltip(val) {
        return fmtSI(val, unitSuffix);
    }

    function statusOverlayPlugin() {
        if (!statusData || !statusData.length) return {};

        return {
            hooks: {
                draw: function(u) {
                    var ctx = u.ctx;
                    ctx.save();
                    ctx.fillStyle = 'rgba(239, 68, 68, 0.12)';

                    var downStart = null;
                    for (var i = 0; i < statusData.length; i++) {
                        var ts     = statusData[i][0];
                        var status = parseFloat(statusData[i][1]);

                        if (status !== 1 && downStart === null) {
                            downStart = ts;
                        } else if (status === 1 && downStart !== null) {
                            var x0 = u.valToPos(downStart, 'x', true);
                            var x1 = u.valToPos(ts, 'x', true);
                            ctx.fillRect(x0, u.bbox.top, x1 - x0, u.bbox.height);
                            downStart = null;
                        }
                    }

                    // If still down at the end, shade to the right edge
                    if (downStart !== null) {
                        var x0 = u.valToPos(downStart, 'x', true);
                        var x1 = u.bbox.left + u.bbox.width;
                        ctx.fillRect(x0, u.bbox.top, x1 - x0, u.bbox.height);
                    }

                    ctx.restore();
                }
            }
        };
    }

    function tooltipPlugin() {
        var tt = document.createElement('div');
        tt.style.cssText = 'position:absolute;pointer-events:none;padding:6px 10px;border-radius:4px;font-size:12px;background:rgba(30,30,30,0.9);color:#fff;z-index:100;display:none;white-space:nowrap;';

        return {
            hooks: {
                init: function(u) { u.over.appendChild(tt); },
                setCursor: function(u) {
                    var idx = u.cursor.idx;
                    if (idx == null) { tt.style.display = 'none'; return; }

                    var ts   = u.data[0][idx];
                    var rx   = u.data[1][idx];
                    var tx   = u.data[2][idx];
                    var date = new Date(ts * 1000);
                    var str  = date.toLocaleDateString('en-AU') + ' ' + date.toLocaleTimeString('en-AU');

                    tt.innerHTML =
                        '<strong>' + str + '</strong><br>' +
                        '<span style="color:' + rxStroke + '">\u25B2 RX:</span> ' + fmtTooltip(rx) + '<br>' +
                        '<span style="color:' + txStroke + '">\u25BC TX:</span> ' + fmtTooltip(tx);

                    var left = u.cursor.left + 10;
                    if (left + 180 > u.over.clientWidth) left = u.cursor.left - 180;
                    tt.style.left = left + 'px';
                    tt.style.top  = Math.max(0, u.cursor.top - 40) + 'px';
                    tt.style.display = 'block';
                }
            }
        };
    }

    function getWidth() {
        var el = document.getElementById(graphId);
        if (!el) return 800;
        // Walk up to find a parent with a real width (card-body, col, etc.)
        var node = el;
        while (node && node.clientWidth <= 0) {
            node = node.parentElement;
        }
        var w = node ? node.clientWidth : 800;
        // Account for padding on the immediate parent (card-body)
        if (node && node !== el) {
            var cs = getComputedStyle(node);
            w -= parseFloat(cs.paddingLeft || 0) + parseFloat(cs.paddingRight || 0);
        }
        return Math.floor(w);
    }

    function renderGraph() {
        var el = document.getElementById(graphId);
        if (!el || typeof uPlot === 'undefined') return;

        var width = getWidth();
        if (width <= 0) return;

        var opts = {
            width: width,
            height: 300,
            plugins: [statusOverlayPlugin(), tooltipPlugin()],
            cursor: { drag: { x: true, y: false } },
            legend: { show: false },
            scales: {
                x: { time: true },
                y: {
                    range: function(u, dmin, dmax) {
                        var top = (dmax || 1) * 1.15;
                        return [0, top];
                    }
                }
            },
            axes: [
                {
                    stroke: '#888',
                    grid: { stroke: 'rgba(0,0,0,0.07)', width: 1 },
                    ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 },
                    values: [
                        // [tick incr,  default,         year,                            month,   day,                       hour,   min,           sec,   mode]
                        [3600 * 24 * 365, "{YYYY}",        null,                            null,    null,                      null,   null,          null,  1],
                        [3600 * 24 * 28,  "{MMM}",         "\n{YYYY}",                      null,    null,                      null,   null,          null,  1],
                        [3600 * 24,       "{D}/{M}",       "\n{YYYY}",                      null,    null,                      null,   null,          null,  1],
                        [3600,            "{HH}:{mm}",     "\n{D}/{M}/{YYYY}",              null,    "\n{D}/{M}",               null,   null,          null,  1],
                        [60,              "{HH}:{mm}",     "\n{D}/{M}/{YYYY}",              null,    "\n{D}/{M}",               null,   null,          null,  1],
                        [1,               ":{ss}",         "\n{D}/{M}/{YYYY} {HH}:{mm}",    null,    "\n{D}/{M} {HH}:{mm}",     null,   "\n{HH}:{mm}", null,  1],
                        [0.001,           ":{ss}.{fff}",   "\n{D}/{M}/{YYYY} {HH}:{mm}",    null,    "\n{D}/{M} {HH}:{mm}",     null,   "\n{HH}:{mm}", null,  1],
                    ]
                },
                {
                    stroke: '#888',
                    grid: { stroke: 'rgba(0,0,0,0.07)', width: 1 },
                    ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 },
                    size: 90,
                    values: function(u, splits) {
                        return splits.map(function(v) { return fmtAxis(v); });
                    }
                }
            ],
            series: [
                {},
                {
                    label: 'RX (In)',
                    stroke: rxStroke,
                    fill: rxFill,
                    width: 1.5
                },
                {
                    label: 'TX (Out)',
                    stroke: txStroke,
                    fill: txFill,
                    width: 1.5
                }
            ]
        };

        el.innerHTML = '';
        new uPlot(opts, [timestamps, rxValues, txValues], el);
    }

    // Load uPlot CSS + JS if not already loaded, then render
    if (typeof uPlot === 'undefined') {
        if (!document.querySelector('link[href*="uPlot"]')) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = '/vendor/uplot/uPlot.min.css';
            document.head.appendChild(link);
        }

        // Use a shared callback queue so all graphs render once uPlot loads
        window._uplotQueue = window._uplotQueue || [];
        window._uplotQueue.push(renderGraph);

        if (!window._uplotLoading) {
            window._uplotLoading = true;
            var script = document.createElement('script');
            script.src = '/vendor/uplot/uPlot.min.js';
            script.onload = function() {
                window._uplotQueue.forEach(function(fn) { fn(); });
                window._uplotQueue = [];
            };
            document.head.appendChild(script);
        }
    } else {
        renderGraph();
    }

    // Re-render on container resize (handles initial layout + window resize)
    var resizeTimer;
    var lastWidth = 0;
    if (typeof ResizeObserver !== 'undefined') {
        var el = document.getElementById(graphId);
        var observed = el ? (el.parentElement || el) : null;
        if (observed) {
            new ResizeObserver(function() {
                var w = getWidth();
                if (w > 0 && w !== lastWidth) {
                    lastWidth = w;
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function() {
                        if (typeof uPlot !== 'undefined') renderGraph();
                    }, 150);
                }
            }).observe(observed);
        }
    } else {
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (typeof uPlot !== 'undefined') renderGraph();
            }, 200);
        });
    }
})();
</script>

<?php endif; ?>
