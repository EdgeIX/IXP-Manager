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

    // Colour scheme: aggregate/LAG graphs use a distinct palette
    $isAggregate = ( $graph instanceof \IXP\Services\Grapher\Graph\VirtualInterface )
                || ( $graph instanceof \IXP\Services\Grapher\Graph\Customer );

    if( $isAggregate ) {
        $rxStroke = '#8b5cf6';  // purple
        $rxFill   = 'rgba(139, 92, 246, 0.25)';
        $txStroke = '#f59e0b';  // amber
        $txFill   = 'rgba(245, 158, 11, 0.25)';
    } else {
        $rxStroke = '#22c55e';  // green
        $rxFill   = 'rgba(34, 197, 94, 0.25)';
        $txStroke = '#3b82f6';  // blue
        $txFill   = 'rgba(59, 130, 246, 0.25)';
    }
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
    var graphId  = <?= json_encode( $graphId ) ?>;
    var isBits   = <?= json_encode( $isBits ) ?>;

    var rxStroke = <?= json_encode( $rxStroke ) ?>;
    var rxFill   = <?= json_encode( $rxFill ) ?>;
    var txStroke = <?= json_encode( $txStroke ) ?>;
    var txFill   = <?= json_encode( $txFill ) ?>;

    var timestamps = <?= json_encode( $timestamps ) ?>;
    var rxValues   = <?= json_encode( $rxValues ) ?>;
    var txValues   = <?= json_encode( $txValues ) ?>;

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
        return isBits ? fmtSI(val, 'bps') : fmtSI(val, 'pps');
    }

    function fmtTooltip(val) {
        return isBits ? fmtSI(val, 'bps') : fmtSI(val, 'pps');
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
                    var str  = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();

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

    function renderGraph() {
        var el = document.getElementById(graphId);
        if (!el || typeof uPlot === 'undefined') return;

        var width = el.clientWidth || el.parentElement.clientWidth || 800;

        var opts = {
            width: width,
            height: 300,
            plugins: [tooltipPlugin()],
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
                    ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 }
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

    // Debounced resize
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (typeof uPlot !== 'undefined') renderGraph();
        }, 200);
    });
})();
</script>

<?php endif; ?>
