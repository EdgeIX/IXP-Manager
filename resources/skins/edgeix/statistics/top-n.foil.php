<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );

    $topPorts  = $t->topPorts;
    $limit     = $t->limit;
    $direction = $t->direction;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Top <?= $limit ?> Ports by Traffic (<?= $direction === 'out' ? 'TX' : 'RX' ?>)
<?php $this->append() ?>

<?php $this->section('content') ?>
    <div class="row">
        <div class="col-sm-12">
            <?= $t->alerts() ?>

            <form method="GET" action="<?= route( 'statistics@top-n' ) ?>">
                <nav id="filter-row" class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                    <span class="navbar-brand">Options:</span>

                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="collapse navbar-collapse" id="navbarNavDropdown">
                        <div class="form-inline">
                            <label for="direction" class="mr-2">Direction:</label>
                            <select id="direction" name="direction" class="form-control mr-3">
                                <option value="in" <?= $direction === 'in' ? 'selected' : '' ?>>RX (In)</option>
                                <option value="out" <?= $direction === 'out' ? 'selected' : '' ?>>TX (Out)</option>
                            </select>

                            <label for="limit" class="mr-2">Show:</label>
                            <select id="limit" name="limit" class="form-control mr-3">
                                <?php foreach( [ 10, 20, 50, 100 ] as $l ): ?>
                                    <option value="<?= $l ?>" <?= $limit === $l ? 'selected' : '' ?>>Top <?= $l ?></option>
                                <?php endforeach; ?>
                            </select>

                            <input type="submit" class="btn btn-white" value="Update">
                        </div>
                    </div>
                </nav>
            </form>

            <div class="card">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th class="text-center" style="width: 50px;">#</th>
                                <th>Customer</th>
                                <th>Port</th>
                                <th>Location</th>
                                <th class="text-right">Current <?= $direction === 'out' ? 'TX' : 'RX' ?></th>
                                <th class="text-right">Port Speed</th>
                                <th class="text-right" style="width: 120px;">Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if( empty( $topPorts ) ): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No traffic data available. Is VictoriaMetrics configured?
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach( $topPorts as $rank => $port ): ?>
                                    <tr>
                                        <td class="text-center text-muted"><?= $rank + 1 ?></td>
                                        <td>
                                            <?php if( $port['customer_id'] ): ?>
                                                <a href="<?= route( 'customer@overview', [ 'cust' => $port['customer_id'] ] ) ?>">
                                                    <?= $t->ee( $port['customer_name'] ) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if( $port['pi_id'] ): ?>
                                                <a href="<?= route( 'statistics@member-drilldown', [ 'type' => 'pi', 'typeid' => $port['pi_id'] ] ) ?>?category=bits">
                                                    <code><?= $t->ee( $port['device_interface'] ) ?></code>
                                                </a>
                                            <?php else: ?>
                                                <code><?= $t->ee( $port['device_interface'] ) ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $port['location'] ? $t->ee( $port['location'] ) : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-right">
                                            <?= $t->scaleBits( $port['rate_bps'], 1 ) ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if( $port['speed_mbps'] > 0 ): ?>
                                                <?= $t->scaleBits( $port['speed_mbps'] * 1000000, 0 ) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if( $port['util_pct'] !== null ): ?>
                                                <?php
                                                    $pct = $port['util_pct'];
                                                    $badge = $pct > 80 ? 'danger' : ( $pct > 50 ? 'warning' : 'success' );
                                                ?>
                                                <span class="badge badge-<?= $badge ?>" style="font-size: 0.9em; min-width: 55px;">
                                                    <?= number_format( $pct, 1 ) ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php $this->append() ?>
