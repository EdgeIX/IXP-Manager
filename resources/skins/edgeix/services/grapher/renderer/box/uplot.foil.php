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
    $graphId  = 'uplot-' . md5( $graph->identifier() . $category . $period );

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
    $categoryLabels = [
        'bits'   => [ 'label' => 'Bits/s',    'unit' => 'bps',  'scale' => 'bits' ],
        'pkts'   => [ 'label' => 'Packets/s',  'unit' => 'pps',  'scale' => 'metric' ],
        'errs'   => [ 'label' => 'Errors/s',   'unit' => 'eps',  'scale' => 'metric' ],
        'discs'  => [ 'label' => 'Discards/s', 'unit' => 'dps',  'scale' => 'metric' ],
        'bcasts' => [ 'label' => 'Broadcasts/s', 'unit' => 'bps', 'scale' => 'metric' ],
    ];

    $catInfo = $categoryLabels[ $category ] ?? $categoryLabels['bits'];
?>

<?php if( empty( $data ) ): ?>
    <div class="alert alert-info">
        No data available for this graph.
    </div>
<?php else: ?>

<div class="uplot-graph-container" style="margin-bottom: 1rem;">
    <div id="<?= $graphId ?>" style="width: 100%;"></div>

    <table class="table table-sm table-borderless mt-2" style="font-size: 0.85rem; max-width: 500px;">
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
                <td><span style="color: #22c55e; font-weight: bold;">RX (In)</span></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->maxIn(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->averageIn(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->curIn(), $category ) ?></td>
            </tr>
            <tr>
                <td><span style="color: #3b82f6; font-weight: bold;">TX (Out)</span></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->maxOut(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->averageOut(), $category ) ?></td>
                <td class="text-right"><?= $this->grapher()->scale( $stats->curOut(), $category ) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<script>
(function() {
    var graphId = <?= json_encode( $graphId ) ?>;
    var category = <?= json_encode( $catInfo['scale'] ) ?>;

    var timestamps = <?= json_encode( $timestamps ) ?>;
    var rxValues = <?= json_encode( $rxValues ) ?>;
    var txValues = <?= json_encode( array_map( function( $v ) { return -$v; }, $txValues ) ) ?>;

    function formatValue(val, isBits) {
        var abs = Math.abs(val);
        if (isBits) {
            if (abs >= 1e12) return (val / 1e12).toFixed(2) + ' Tbps';
            if (abs >= 1e9)  return (val / 1e9).toFixed(2) + ' Gbps';
            if (abs >= 1e6)  return (val / 1e6).toFixed(2) + ' Mbps';
            if (abs >= 1e3)  return (val / 1e3).toFixed(2) + ' Kbps';
            return val.toFixed(0) + ' bps';
        } else {
            if (abs >= 1e9)  return (val / 1e9).toFixed(2) + 'G';
            if (abs >= 1e6)  return (val / 1e6).toFixed(2) + 'M';
            if (abs >= 1e3)  return (val / 1e3).toFixed(2) + 'K';
            return val.toFixed(0);
        }
    }

    function axisFormatter(self, ticks, space, incr) {
        return ticks.map(function(v) {
            return formatValue(v, category === 'bits');
        });
    }

    function tooltipPlugin() {
        var tooltip = document.createElement('div');
        tooltip.style.cssText = 'position:absolute;pointer-events:none;padding:6px 10px;border-radius:4px;font-size:12px;background:rgba(30,30,30,0.9);color:#fff;z-index:100;display:none;white-space:nowrap;';

        return {
            hooks: {
                init: function(u) {
                    u.over.appendChild(tooltip);
                },
                setCursor: function(u) {
                    var idx = u.cursor.idx;
                    if (idx == null) {
                        tooltip.style.display = 'none';
                        return;
                    }

                    var ts = u.data[0][idx];
                    var rx = u.data[1][idx];
                    var tx = u.data[2][idx];
                    var date = new Date(ts * 1000);
                    var dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();

                    var isBits = category === 'bits';
                    tooltip.innerHTML =
                        '<strong>' + dateStr + '</strong><br>' +
                        '<span style="color:#22c55e">RX:</span> ' + formatValue(rx, isBits) + '<br>' +
                        '<span style="color:#3b82f6">TX:</span> ' + formatValue(Math.abs(tx), isBits);

                    var left = u.cursor.left + 10;
                    var top = u.cursor.top - 10;

                    if (left + 200 > u.over.clientWidth) {
                        left = u.cursor.left - 200;
                    }

                    tooltip.style.left = left + 'px';
                    tooltip.style.top = top + 'px';
                    tooltip.style.display = 'block';
                }
            }
        };
    }

    function renderGraph() {
        var el = document.getElementById(graphId);
        if (!el) return;

        var width = el.parentElement.clientWidth || 800;

        var opts = {
            width: width,
            height: 250,
            plugins: [tooltipPlugin()],
            cursor: {
                drag: { x: true, y: false }
            },
            scales: {
                x: { time: true },
                y: {
                    auto: true,
                    range: function(u, dmin, dmax) {
                        // Symmetric around 0 for TX/RX mirror
                        var absMax = Math.max(Math.abs(dmin), Math.abs(dmax));
                        // Add 10% padding
                        absMax = absMax * 1.1;
                        return [-absMax, absMax];
                    }
                }
            },
            axes: [
                {
                    stroke: '#666',
                    grid: { stroke: 'rgba(0,0,0,0.06)' }
                },
                {
                    stroke: '#666',
                    grid: { stroke: 'rgba(0,0,0,0.06)' },
                    values: axisFormatter,
                    size: 70
                }
            ],
            series: [
                {},
                {
                    label: 'RX (In)',
                    stroke: '#22c55e',
                    fill: 'rgba(34, 197, 94, 0.15)',
                    width: 1.5,
                    paths: uPlot.paths.stepped({ align: 1 })
                },
                {
                    label: 'TX (Out)',
                    stroke: '#3b82f6',
                    fill: 'rgba(59, 130, 246, 0.15)',
                    width: 1.5,
                    paths: uPlot.paths.stepped({ align: 1 })
                }
            ]
        };

        el.innerHTML = '';
        new uPlot(opts, [timestamps, rxValues, txValues], el);
    }

    // Load uPlot if not already loaded
    if (typeof uPlot === 'undefined') {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/vendor/uplot/uPlot.min.css';
        document.head.appendChild(link);

        var script = document.createElement('script');
        script.src = '/vendor/uplot/uPlot.min.js';
        script.onload = renderGraph;
        document.head.appendChild(script);
    } else {
        renderGraph();
    }

    // Responsive resize
    window.addEventListener('resize', function() {
        if (typeof uPlot !== 'undefined') {
            renderGraph();
        }
    });
})();
</script>

<?php endif; ?>
