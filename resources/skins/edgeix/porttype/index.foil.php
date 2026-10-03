<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

$portTypes = $t->portTypes;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Port Types
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a class="btn btn-white" href="<?= route( 'port-stock@index' ) ?>">
            <i class="fa fa-cubes"></i> Port Stock
        </a>
        <a class="btn btn-white" href="<?= route( 'port-type@create' ) ?>">
            <i class="fa fa-plus"></i> Add Port Type
        </a>
    </div>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">

            <?= $t->alerts() ?>

            <div class="card">
                <div class="card-header">
                    Sellable port-type catalogue — drives transceiver-detection mapping and (Phase 3) the order form.
                    Detection: <code>artisan switch:detect-transceivers</code>.
                </div>
                <div class="card-body">
                    <table class="table table-striped table-sm">
                        <thead class="thead-dark">
                            <tr>
                                <th>Name</th>
                                <th>Speed</th>
                                <th>Active</th>
                                <th>Priority</th>
                                <th>Match patterns (regex, one per line)</th>
                                <th>Low-stock threshold</th>
                                <th title="Every switch port whose detected optic classifies as this type — all states (in service, free, core). For sellable availability see Port Stock.">Detected ports (fleet total)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach( $portTypes as $pt ): ?>
                                <tr>
                                    <td><?= $t->ee( $pt->name ) ?></td>
                                    <td><?= $pt->speedLabel() ?></td>
                                    <td>
                                        <?php if( $pt->active ): ?>
                                            <span class="badge badge-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$pt->priority ?></td>
                                    <td><code class="tw-text-xs tw-whitespace-pre-line"><?= $t->ee( $pt->match_patterns ?? '' ) ?></code></td>
                                    <td><?= $pt->low_stock_threshold !== null ? (int)$pt->low_stock_threshold : '—' ?></td>
                                    <td><?= (int)$pt->switch_ports_count ?></td>
                                    <td class="text-right tw-whitespace-nowrap">
                                        <a class="btn btn-white btn-sm" href="<?= route( 'port-type@edit', [ 'portType' => $pt->id ] ) ?>">
                                            <i class="fa fa-pencil"></i>
                                        </a>
                                        <form method="POST" action="<?= route( 'port-type@delete', [ 'portType' => $pt->id ] ) ?>" class="d-inline"
                                              onsubmit="return confirm( 'Delete port type <?= $t->ee( $pt->name ) ?>?' );">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-white btn-sm"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
