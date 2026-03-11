<?php
    /**
     * EdgIX skin: DOM (Digital Optical Monitoring) chart.
     *
     * Renders RX/TX optical power (dBm) time series using uPlot.
     * Supports multi-channel optics (breakouts).
     *
     * Expected variables:
     *   $t->domData   — array from VictoriaMetrics::domData()
     *   $t->domLabel  — string label for the port (e.g. "pe1syd3:Ethernet9/2")
     *   $t->domPeriod — graph period constant
     */

    $domData  = $t->domData;
    $channels = $domData['channels'] ?? [];
    $domId    = 'dom-' . md5( ( $t->domLabel ?? '' ) . ( $t->domPeriod ?? '' ) . uniqid() );

    // Channel colour palette (RX stroke, RX fill, TX stroke, TX fill)
    // Optical-themed: distinct warm/cool pairing per channel
    $channelColours = [
        [ '#e67e22', 'rgba(230, 126, 34, 0.15)',  '#8e44ad', 'rgba(142, 68, 173, 0.15)' ],   // orange / purple
        [ '#d4a017', 'rgba(212, 160, 23, 0.15)',  '#c0392b', 'rgba(192, 57, 43, 0.15)' ],    // gold / crimson
        [ '#e88d2a', 'rgba(232, 141, 42, 0.15)',  '#2980b9', 'rgba(41, 128, 185, 0.15)' ],   // tangerine / steel blue
        [ '#f1c40f', 'rgba(241, 196, 15, 0.15)',  '#16a085', 'rgba(22, 160, 133, 0.15)' ],   // yellow / teal
    ];
?>

<?php if( empty( $channels ) || ( count( $channels ) === 1 && empty( $channels[0]['rx'] ) && empty( $channels[0]['tx'] ) ) ): ?>
    <div class="text-muted small py-1">No DOM data available</div>
<?php else: ?>

<div class="dom-chart-container" style="margin-top: 0.25rem;">
    <div class="d-flex align-items-center mb-1">
        <small class="text-muted font-weight-bold">Optical Power (dBm)</small>
    </div>
    <div id="<?= $domId ?>" style="width: 100%; min-width: 0;"></div>

    <?php
        // Build stats table from the data
        foreach( $channels as $ci => $ch ):
            $rxVals = array_map( fn( $p ) => (float) $p[1], $ch['rx'] );
            $txVals = array_map( fn( $p ) => (float) $p[1], $ch['tx'] );
            // Filter out -30 (no light / SFP not present sentinel)
            $rxVals = array_filter( $rxVals, fn( $v ) => $v > -30 );
            $txVals = array_filter( $txVals, fn( $v ) => $v > -30 );
            $rxCur = !empty( $rxVals ) ? end( $rxVals ) : null;
            $txCur = !empty( $txVals ) ? end( $txVals ) : null;
            $rxAvg = !empty( $rxVals ) ? array_sum( $rxVals ) / count( $rxVals ) : null;
            $txAvg = !empty( $txVals ) ? array_sum( $txVals ) / count( $txVals ) : null;
            $rxMin = !empty( $rxVals ) ? min( $rxVals ) : null;
            $txMin = !empty( $txVals ) ? min( $txVals ) : null;
            $rxMax = !empty( $rxVals ) ? max( $rxVals ) : null;
            $txMax = !empty( $txVals ) ? max( $txVals ) : null;
            $colours = $channelColours[ $ci % count( $channelColours ) ];
            $chLabel = count( $channels ) > 1 ? " (Ch {$ch['channel_index']})" : '';
    ?>
    <table class="table table-sm table-borderless mt-1 mb-0" style="font-size: 0.8rem; max-width: 500px;">
        <thead>
            <tr>
                <th></th>
                <th class="text-right">Min</th>
                <th class="text-right">Avg</th>
                <th class="text-right">Max</th>
                <th class="text-right">Current</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span style="color: <?= $colours[0] ?>; font-weight: bold;">RX<?= $chLabel ?></span></td>
                <td class="text-right"><?= $rxMin !== null ? number_format( $rxMin, 2 ) . ' dBm' : 'N/A' ?></td>
                <td class="text-right"><?= $rxAvg !== null ? number_format( $rxAvg, 2 ) . ' dBm' : 'N/A' ?></td>
                <td class="text-right"><?= $rxMax !== null ? number_format( $rxMax, 2 ) . ' dBm' : 'N/A' ?></td>
                <td class="text-right"><?= $rxCur !== null ? number_format( $rxCur, 2 ) . ' dBm' : 'N/A' ?></td>
            </tr>
            <tr>
                <td><span style="color: <?= $colours[2] ?>; font-weight: bold;">TX<?= $chLabel ?></span></td>
                <td class="text-right"><?= $txMin !== null ? number_format( $txMin, 2 ) . ' dBm' : 'N/A' ?></td>
                <td class="text-right"><?= $txAvg !== null ? number_format( $txAvg, 2 ) . ' dBm' : 'N/A' ?></td>
                <td class="text-right"><?= $txMax !== null ? number_format( $txMax, 2 ) . ' dBm' : 'N/A' ?></td>
                <td class="text-right"><?= $txCur !== null ? number_format( $txCur, 2 ) . ' dBm' : 'N/A' ?></td>
            </tr>
        </tbody>
    </table>
    <?php endforeach; ?>
</div>

<script>
(function() {
    var domId = <?= json_encode( $domId ) ?>;
    var channelsData = <?= json_encode( $channels ) ?>;
    var channelColours = <?= json_encode( $channelColours ) ?>;

    function renderDom() {
        var el = document.getElementById(domId);
        if (!el || typeof uPlot === 'undefined' || !channelsData.length) return;

        var width = el.clientWidth || el.parentElement.clientWidth || 800;

        // Build uPlot data: [timestamps, ch0_rx, ch0_tx, ch1_rx, ch1_tx, ...]
        // Use the first channel's RX timestamps as the reference
        var refSeries = channelsData[0].rx.length ? channelsData[0].rx : channelsData[0].tx;
        if (!refSeries.length) return;

        var timestamps = refSeries.map(function(p) { return p[0]; });
        var uData = [timestamps];
        var series = [{}];

        for (var i = 0; i < channelsData.length; i++) {
            var ch = channelsData[i];
            var colours = channelColours[i % channelColours.length];
            var chSuffix = channelsData.length > 1 ? ' Ch' + ch.channel_index : '';

            // Index RX/TX by timestamp
            var rxMap = {};
            ch.rx.forEach(function(p) { rxMap[p[0]] = parseFloat(p[1]); });
            var txMap = {};
            ch.tx.forEach(function(p) { txMap[p[0]] = parseFloat(p[1]); });

            var rxArr = timestamps.map(function(ts) {
                var v = rxMap[ts];
                return (v !== undefined && v > -30) ? v : null;
            });
            var txArr = timestamps.map(function(ts) {
                var v = txMap[ts];
                return (v !== undefined && v > -30) ? v : null;
            });

            uData.push(rxArr);
            series.push({
                label: 'RX' + chSuffix,
                stroke: colours[0],
                fill: colours[1],
                width: 1.5
            });

            uData.push(txArr);
            series.push({
                label: 'TX' + chSuffix,
                stroke: colours[2],
                fill: colours[3],
                width: 1.5
            });
        }

        // Tooltip plugin
        var tt = document.createElement('div');
        tt.style.cssText = 'position:absolute;pointer-events:none;padding:6px 10px;border-radius:4px;font-size:12px;background:rgba(30,30,30,0.9);color:#fff;z-index:100;display:none;white-space:nowrap;';

        var tooltipPlugin = {
            hooks: {
                init: function(u) { u.over.appendChild(tt); },
                setCursor: function(u) {
                    var idx = u.cursor.idx;
                    if (idx == null) { tt.style.display = 'none'; return; }

                    var ts   = u.data[0][idx];
                    var date = new Date(ts * 1000);
                    var str  = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
                    var html = '<strong>' + str + '</strong>';

                    for (var s = 1; s < u.data.length; s++) {
                        var val = u.data[s][idx];
                        var colour = u.series[s].stroke;
                        var label  = u.series[s].label;
                        html += '<br><span style="color:' + colour + '">' + label + ':</span> ';
                        html += val !== null ? val.toFixed(2) + ' dBm' : 'N/A';
                    }

                    tt.innerHTML = html;
                    var left = u.cursor.left + 10;
                    if (left + 200 > u.over.clientWidth) left = u.cursor.left - 200;
                    tt.style.left = left + 'px';
                    tt.style.top  = Math.max(0, u.cursor.top - 40) + 'px';
                    tt.style.display = 'block';
                }
            }
        };

        var opts = {
            width: width,
            height: 180,
            plugins: [tooltipPlugin],
            cursor: { drag: { x: true, y: false } },
            legend: { show: false },
            scales: {
                x: { time: true },
                y: {
                    range: function(u, dmin, dmax) {
                        // Sensible range for dBm values
                        var min = dmin !== null ? Math.floor(dmin - 1) : -15;
                        var max = dmax !== null ? Math.ceil(dmax + 1) : 5;
                        return [min, max];
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
                    size: 60,
                    values: function(u, splits) {
                        return splits.map(function(v) { return v.toFixed(1) + ' dBm'; });
                    }
                }
            ],
            series: series
        };

        el.innerHTML = '';
        new uPlot(opts, uData, el);
    }

    // Use shared uPlot loading queue
    if (typeof uPlot === 'undefined') {
        window._uplotQueue = window._uplotQueue || [];
        window._uplotQueue.push(renderDom);

        if (!window._uplotLoading) {
            window._uplotLoading = true;
            if (!document.querySelector('link[href*="uPlot"]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = '/vendor/uplot/uPlot.min.css';
                document.head.appendChild(link);
            }
            var script = document.createElement('script');
            script.src = '/vendor/uplot/uPlot.min.js';
            script.onload = function() {
                window._uplotQueue.forEach(function(fn) { fn(); });
                window._uplotQueue = [];
            };
            document.head.appendChild(script);
        }
    } else {
        renderDom();
    }

    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (typeof uPlot !== 'undefined') renderDom();
        }, 200);
    });
})();
</script>

<?php endif; ?>
