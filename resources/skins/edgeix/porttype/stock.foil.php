<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

$stockMatrix       = $t->stockMatrix;
$stockLocations    = $t->stockLocations;
$stockTypes        = $t->stockTypes;
$stockSellable     = $t->stockSellable;
$stockNeedsPrewire = $t->stockNeedsPrewire;
$stockUnmatched    = $t->stockUnmatched;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Port Stock
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a class="btn btn-white" href="<?= route( 'port-type@index' ) ?>">
            <i class="fa fa-list"></i> Port Types
        </a>
    </div>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">

            <?= $t->alerts() ?>

            <div class="card mb-4">
                <div class="card-header">
                    Sellable stock by location — <strong>sellable</strong> = active peering switch port,
                    port type known, no service provisioned, panel side marked <em>Prewired</em>.
                    This is what the order form will offer.
                </div>
                <div class="card-body">
                    <?php if( !count( $stockLocations ) ): ?>
                        <p class="tw-text-gray-500 mb-0">
                            No sellable stock found. Run <code>artisan switch:detect-transceivers</code> to
                            populate port types, and check the hygiene lists below.
                        </p>
                    <?php else: ?>
                        <table class="table table-sm table-striped">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Location / DC</th>
                                    <?php foreach( $stockTypes as $pt ): ?>
                                        <th class="text-center"><?= $t->ee( $pt->name ) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach( $stockLocations as $loc ): ?>
                                    <tr>
                                        <td><?= $t->ee( $loc->name ) ?></td>
                                        <?php foreach( $stockTypes as $pt ):
                                            $n = $stockMatrix[ $loc->id ][ $pt->id ] ?? 0;
                                            $low = $pt->low_stock_threshold !== null && $n < $pt->low_stock_threshold;
                                        ?>
                                            <td class="text-center">
                                                <?php if( $n === 0 ): ?>
                                                    <span class="tw-text-gray-400">0</span>
                                                <?php else: ?>
                                                    <strong><?= $n ?></strong>
                                                <?php endif; ?>
                                                <?php if( $low ): ?>
                                                    <span class="badge badge-danger" title="Below low-stock threshold of <?= (int)$pt->low_stock_threshold ?>">low</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Sellable ports (<?= count( $stockSellable ) ?>)</div>
                <div class="card-body">
                    <table class="table table-sm table-striped">
                        <thead class="thead-dark">
                            <tr>
                                <th>Location</th><th>Switch</th><th>Port</th><th>Port type</th>
                                <th>Detected optic</th><th>Patch panel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach( $stockSellable as $r ): ?>
                                <tr>
                                    <td><?= $t->ee( $r->location?->name ?? '—' ) ?></td>
                                    <td><?= $t->ee( $r->sp->switcher?->name ?? '—' ) ?></td>
                                    <td><?= $t->ee( $r->sp->ifName ) ?></td>
                                    <td>
                                        <?= $t->ee( $r->type->name ) ?>
                                        <?php if( $r->sp->port_type_override_id ): ?>
                                            <span class="badge badge-info" title="Admin override — detection ignored">override</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="tw-text-xs"><?= $t->ee( $r->sp->detected_xcvr ?? '—' ) ?></code></td>
                                    <td>
                                        <?php if( $r->ppp ): ?>
                                            <a href="<?= route( 'patch-panel-port@list-for-patch-panel', [ 'pp' => $r->ppp->patch_panel_id ] ) ?>">
                                                <?= $t->ee( $r->ppp->patchPanel?->name ?? 'panel' ) ?> #<?= (int)$r->ppp->number ?>
                                            </a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-4 border-warning">
                <div class="card-header">
                    Hygiene: free typed ports NOT marked prewired (<?= count( $stockNeedsPrewire ) ?>)
                    — invisible to ordering until the panel port is set to <em>Prewired</em> (or they genuinely aren't patched).
                </div>
                <div class="card-body">
                    <?php if( !count( $stockNeedsPrewire ) ): ?>
                        <p class="tw-text-gray-500 mb-0">None — panel data is clean.</p>
                    <?php else: ?>
                        <table class="table table-sm table-striped">
                            <thead class="thead-dark">
                                <tr><th>Location</th><th>Switch</th><th>Port</th><th>Port type</th><th>Panel port state</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach( $stockNeedsPrewire as $r ): ?>
                                    <tr>
                                        <td><?= $t->ee( $r->location?->name ?? '—' ) ?></td>
                                        <td><?= $t->ee( $r->sp->switcher?->name ?? '—' ) ?></td>
                                        <td><?= $t->ee( $r->sp->ifName ) ?></td>
                                        <td><?= $t->ee( $r->type->name ) ?></td>
                                        <td>
                                            <?php if( $r->ppp ): ?>
                                                <?= $t->ee( \IXP\Models\PatchPanelPort::$STATES[ $r->ppp->state ] ?? (string)$r->ppp->state ) ?>
                                            <?php else: ?>
                                                <span class="badge badge-warning">no panel port linked</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4 border-danger">
                <div class="card-header">
                    Hygiene: free ports with a detected optic but NO catalogue match (<?= count( $stockUnmatched ) ?>)
                    — add or adjust match patterns in <a href="<?= route( 'port-type@index' ) ?>">Port Types</a>.
                </div>
                <div class="card-body">
                    <?php if( !count( $stockUnmatched ) ): ?>
                        <p class="tw-text-gray-500 mb-0">None — every detected optic maps to a type.</p>
                    <?php else: ?>
                        <table class="table table-sm table-striped">
                            <thead class="thead-dark">
                                <tr><th>Location</th><th>Switch</th><th>Port</th><th>Detected optic</th><th>Detected at</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach( $stockUnmatched as $r ): ?>
                                    <tr>
                                        <td><?= $t->ee( $r->location?->name ?? '—' ) ?></td>
                                        <td><?= $t->ee( $r->sp->switcher?->name ?? '—' ) ?></td>
                                        <td><?= $t->ee( $r->sp->ifName ) ?></td>
                                        <td><code class="tw-text-xs"><?= $t->ee( $r->sp->detected_xcvr ) ?></code></td>
                                        <td><?= $t->ee( (string)$r->sp->detected_xcvr_at ) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
