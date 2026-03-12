<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );
    $isSuperUser = Auth::check() ? Auth::getUser()->isSuperUser(): false;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <?php if( Auth::check() && $isSuperUser ): ?>
        <a href="<?= route( 'customer@overview', [ 'cust' => $t->c->id ] ) ?>" >
            <?= $t->c->getFormattedName() ?>
        </a>
        /
        <a href="<?= route( 'statistics@member', [ 'cust' => $t->c->id ] ) ?>" >
            Statistics
        </a>
        /
        <a href="<?= route( 'statistics@p2ps-get', [ 'customer' => $t->c->id ] ) ?>" >
            Peer to Peer Graphs
        </a>
        (<?= $t->srcVli->getIPAddress( $t->protocol )->address ?? 'No IP' ?>
            / <?= IXP\Services\Grapher\Graph::resolveCategory( $t->category ) ?>
            / <?= IXP\Services\Grapher\Graph::resolvePeriod( $t->period ) ?>
            / <?= IXP\Services\Grapher\Graph::resolveProtocol( $t->protocol ) ?>
        )

    <?php else: ?>
        Peer to Peer Graphs :: <?= $t->c->getFormattedName() ?>
    <?php endif; ?>
<?php $this->append() ?>

<?php if( Auth::check() && !$isSuperUser ): ?>
    <?php $this->section( 'page-header-postamble' ) ?>
        <?php if( $t->grapher()->canAccessAllCustomerGraphs() ): ?>
            <a class="btn btn-white" href="<?= route( 'statistics@member', [ 'cust' => $t->c->id ] ) ?>">
                All Ports
            </a>
        <?php endif; ?>
    <?php $this->append() ?>
<?php endif; ?>

<?php $this->section('content') ?>
    <div class="row">
        <div class="col-md-12">
            <?= $t->alerts() ?>

            <nav id="filter-row" class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                <div class="navbar-header">
                    <a class="navbar-brand">P2P Graphs</a>
                </div>

                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarNavDropdown">
                    <ul class="navbar-nav">
                        <form class="navbar-form navbar-left form-inline d-block d-lg-flex" action="<?= route( 'statistics@p2ps', [ 'customer' => $this->c->id ] ) ?>" method="post">
                            <li class="nav-item">
                                <div class="nav-link d-flex ">
<!--                                    <label for="select_network" class="col-sm-4 col-lg-3">Interface:</label>-->
                                    <select id="select_network" name="svli" class="form-control">
                                        <?php foreach( $t->srcVlis as $vli ):
                                            /** @var $vli \IXP\Models\VlanInterface */?>
                                            <option value="<?= $vli->id ?>" <?php if( $t->srcVli->id === $vli->id ): ?> selected <?php endif; ?>  >
                                                <?= $vli->vlan->name ?>
                                                :: <?= $vli->getIPAddress( $t->protocol )->address ?? 'No IP - VLI ID: ' . $vli->id ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </li>

                            <?php if( $t->showGraphs ): ?>
                                <li class="nav-item">
                                    <div class="nav-link d-flex ">
<!--                                        <label for="select_category" class="col-sm-4 col-lg-6">Category:</label>-->
                                        <select id="select_category" name="category" class="form-control">
                                            <?php foreach( IXP\Services\Grapher\Graph::CATEGORIES_BITS_PKTS_DESCS as $cvalue => $cname ): ?>
                                                <option value="<?= $cvalue ?>" <?php if( $t->category === $cvalue ): ?> selected <?php endif; ?>  >
                                                    <?= $cname ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </li>

                                <li class="nav-item">
                                    <div class="nav-link d-flex ">
<!--                                        <label for="select_period" class="col-sm-4 col-lg-6">Period:</label>-->
                                        <select id="select_period" name="period" class="form-control">
                                            <?php foreach( IXP\Services\Grapher\Graph::PERIOD_DESCS as $pvalue => $pname ): ?>
                                                <option value="<?= $pvalue ?>" <?php if( $t->period === $pvalue ): ?> selected <?php endif; ?>  >
                                                    <?= $pname ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <div class="nav-link d-flex ">
<!--                                    <label for="select_protocol" class="col-sm-4 col-lg-6">Protocol:</label>-->
                                    <select id="select_protocol" name="protocol" class="form-control">
                                        <?php foreach( IXP\Services\Grapher\Graph::PROTOCOL_REAL_DESCS as $pvalue => $pname ): ?>
                                            <?php if( $t->srcVli->vlan->private || $t->srcVli->ipvxEnabled( $pvalue ) ): ?>
                                                <option value="<?= $pvalue ?>" <?php if( $t->protocol === $pvalue ): ?> selected <?php endif; ?>  >
                                                    <?= $pname ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </li>
                            <li class="nav-item">
                                <div class="nav-link d-flex ">
                                    <select id="select_show_graphs" name="show_graphs" class="form-control">
                                        <option value="show" <?php if(  $t->showGraphs ): ?> selected <?php endif; ?>  >Show Graphs</option>
                                        <option value="hide" <?php if( !$t->showGraphs ): ?> selected <?php endif; ?>  >Hide Graphs</option>
                                    </select>
                                </div>
                            </li>
                            <li class="nav-item">
                                <div class="nav-link d-flex ">
                                    <select id="select_order_by" name="order_by" class="form-control">
                                        <option value="traffic" <?php if( $t->orderBy === 'traffic' ): ?> selected <?php endif; ?>  >Order by Traffic</option>
                                        <option value="name"    <?php if( $t->orderBy !== 'traffic' ): ?> selected <?php endif; ?>  >Order by Name</option>
                                    </select>
                                </div>
                            </li>
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <div class="float-right">
                                <input class="btn btn-white  mr-2" type="submit" name="submit" value="Submit" />
                            </div>
                            <div class="float-right">
                                <a class="btn btn-white mr-2" href="<?= route( 'statistics@p2p-table', [ 'custid' => $t->c->id ] ) ?>">Table</a>
                            </div>
                        </form>
                    </ul>
                </div>
            </nav>
        </div>
    </div>

    <?php
        $dstVlis = $t->dstVlis;
        foreach( $dstVlis as $id => $dvli ) {
            if( !$t->srcVli->vlan->private && !$dvli->ipvxEnabled( $t->protocol ) ) {
                unset( $dstVlis[ $id ] );
            }
        }

        $cnt = 0;
        $total = count( $dstVlis );
        $firstColComplete = false;
    ?>


    <?php if( !$t->showGraphs ): ?>

        <?php if( $t->orderBy === 'traffic' ): ?>
            <div class="row">
                <div class="col-12">
                    <div class="alert alert-info mt-2 mb-3" role="alert">
                        <div class="d-flex align-items-center">
                            <div class="mr-3 text-center">
                                <i class="fa fa-info-circle fa-2x"></i>
                            </div>
                            <div>
                                The volume of traffic shown below is yesterday's total across all possible peering sessions and protocols in both directions.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <ol>
                    <?php
                        foreach( $dstVlis as $dvli ):
                    ?>
                        <li>
                            <a href="<?= route( 'statistics@p2p-get', [ 'srcVli' => $t->srcVli->id, 'dstVli' => $dvli->id ] )
                                . '?category=' . $t->category
                                . '&period='   . $t->period
                                . '&protocol=' . $t->protocol
                            ?>">
                                <?= $dvli->virtualInterface->customer->getFormattedName() ?>
                            </a>
                            <?php if( $t->orderBy === 'traffic' && $dvli->total_traffic ): ?>
                                <span class="tw-tabular-nums">(<?= \IXP\IXP::scaleBytes( $dvli->total_traffic, 1 ) ?>)</span>
                            <?php endif; ?>
                        </li>

                        <?php $cnt++; ?>
                        <?php if( !$firstColComplete && $cnt > ( $total / 2 ) ): ?>
                            </ol>
                            </div>
                            <div class="col-md-6">
                            <ol start="<?= $cnt+1  ?>">
                            <?php $firstColComplete = true; ?>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </ol>
            </div>
        </div>

    <?php else: /* if( !$t->showGraphs ) */ ?>
        <?php
            // Batch-fetch all P2P data in 2 API calls (instead of 2 per peer)
            $batchData = [];
            try {
                $akvorado  = app( \IXP\Services\Akvorado\AkvoradoService::class );
                $batchData = $akvorado->p2pBatchTraffic(
                    $t->srcVli, $dstVlis, $t->period, $t->protocol, $t->category
                );
            } catch( \Throwable $e ) {
                // Silently fall back to empty data
            }

            $isBits     = $t->category === 'bits';
            $unitSuffix = $isBits ? 'bps' : 'pps';
        ?>

        <div class="row">
            <?php foreach( $dstVlis as $dvli ): ?>
                <?php
                    $peerData = $batchData[ $dvli->id ] ?? [];
                    $chartId  = 'p2p-batch-' . $dvli->id;

                    // Extract arrays for chart
                    $ts = array_column( $peerData, 0 );
                    $rx = array_column( $peerData, 1 );
                    $tx = array_column( $peerData, 2 );

                    // Stats
                    $maxRx = !empty( $rx ) ? max( $rx ) : 0;
                    $avgRx = !empty( $rx ) ? array_sum( $rx ) / count( $rx ) : 0;
                    $curRx = !empty( $rx ) ? end( $rx ) : 0;
                    $maxTx = !empty( $tx ) ? max( $tx ) : 0;
                    $avgTx = !empty( $tx ) ? array_sum( $tx ) / count( $tx ) : 0;
                    $curTx = !empty( $tx ) ? end( $tx ) : 0;
                ?>
                <div class="col-md-12 col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4>
                                <?= $dvli->virtualInterface->customer->getFormattedName() ?> :: <?= $dvli->getIPAddress( $t->protocol ) ? $dvli->getIPAddress( $t->protocol )->address : 'No IP' ?>
                            </h4>
                        </div>
                        <div class="card-body">
                            <a href="<?= route( 'statistics@p2p', [ 'srcVli' => $t->srcVli->id, 'dstVli' => $dvli->id ] )
                                . '?category=' . $t->category
                                . '&period='   . $t->period
                                . '&protocol=' . $t->protocol
                            ?>">
                                <?php if( empty( $peerData ) ): ?>
                                    <div class="alert alert-info mb-0">No data available for this graph.</div>
                                <?php else: ?>
                                    <div id="<?= $chartId ?>" class="p2p-batch-chart"
                                         data-ts='<?= json_encode( $ts ) ?>'
                                         data-rx='<?= json_encode( $rx ) ?>'
                                         data-tx='<?= json_encode( $tx ) ?>'
                                         style="width: 100%; min-width: 0;"></div>

                                    <table class="table table-sm table-borderless mt-1 mb-0" style="font-size: 0.8rem; max-width: 400px;">
                                        <thead><tr><th></th><th class="text-right">Max</th><th class="text-right">Average</th><th class="text-right">Current</th></tr></thead>
                                        <tbody>
                                            <tr>
                                                <td><span style="color: #22c55e; font-weight: bold;">RX (In)</span></td>
                                                <td class="text-right"><?= $this->grapher()->scale( $maxRx, $t->category ) ?></td>
                                                <td class="text-right"><?= $this->grapher()->scale( $avgRx, $t->category ) ?></td>
                                                <td class="text-right"><?= $this->grapher()->scale( $curRx, $t->category ) ?></td>
                                            </tr>
                                            <tr>
                                                <td><span style="color: #3b82f6; font-weight: bold;">TX (Out)</span></td>
                                                <td class="text-right"><?= $this->grapher()->scale( $maxTx, $t->category ) ?></td>
                                                <td class="text-right"><?= $this->grapher()->scale( $avgTx, $t->category ) ?></td>
                                                <td class="text-right"><?= $this->grapher()->scale( $curTx, $t->category ) ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <?= $t->insert( 'statistics/js/p2p' ); ?>

    <?php if( $t->showGraphs ): ?>
    <script>
    (function() {
        var unitSuffix = <?= json_encode( $unitSuffix ) ?>;

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

        function renderBatchCharts() {
            document.querySelectorAll('.p2p-batch-chart').forEach(function(el) {
                if (el.dataset.rendered) return;

                var ts = JSON.parse(el.dataset.ts);
                var rx = JSON.parse(el.dataset.rx);
                var tx = JSON.parse(el.dataset.tx);
                var width = el.clientWidth || el.parentElement.clientWidth || 400;

                new uPlot({
                    width: width,
                    height: 200,
                    cursor: { drag: { x: true, y: false } },
                    legend: { show: false },
                    scales: {
                        x: { time: true },
                        y: { range: function(u, dmin, dmax) { return [0, (dmax || 1) * 1.15]; } }
                    },
                    axes: [
                        { stroke: '#888', grid: { stroke: 'rgba(0,0,0,0.07)', width: 1 }, ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 } },
                        { stroke: '#888', grid: { stroke: 'rgba(0,0,0,0.07)', width: 1 }, ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 },
                          size: 70, values: function(u, splits) { return splits.map(function(v) { return fmtSI(v, unitSuffix); }); } }
                    ],
                    series: [
                        {},
                        { label: 'RX (In)',  stroke: '#22c55e', fill: 'rgba(34, 197, 94, 0.25)',  width: 1.5 },
                        { label: 'TX (Out)', stroke: '#3b82f6', fill: 'rgba(59, 130, 246, 0.25)', width: 1.5 }
                    ]
                }, [ts, rx, tx], el);

                el.dataset.rendered = 'true';
            });
        }

        // Load uPlot + render all charts
        if (typeof uPlot === 'undefined') {
            if (!document.querySelector('link[href*="uPlot"]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = '/vendor/uplot/uPlot.min.css';
                document.head.appendChild(link);
            }
            window._uplotQueue = window._uplotQueue || [];
            window._uplotQueue.push(renderBatchCharts);
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
            renderBatchCharts();
        }
    })();
    </script>
    <?php endif; ?>
<?php $this->append() ?>