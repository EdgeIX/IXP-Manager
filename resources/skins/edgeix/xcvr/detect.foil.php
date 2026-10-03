<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

$xcvrSwitch   = $t->xcvrSwitch;
$xcvrDetected = $t->xcvrDetected;
$xcvrUnmapped = $t->xcvrUnmapped;
$xcvrEntities = $t->xcvrEntities;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Detect Transceivers (live) / <?= $t->ee( $xcvrSwitch->name ) ?>
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a class="btn btn-white" href="<?= route( 'switch-xcvr@list', [ 'switch' => $xcvrSwitch->id ] ) ?>">
            <i class="fa fa-database"></i> Stored Transceivers
        </a>
        <a class="btn btn-white" href="<?= route( 'port-stock@index' ) ?>">
            <i class="fa fa-cubes"></i> Port Stock
        </a>
    </div>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">

            <?= $t->alerts() ?>

            <div class="alert alert-info">
                Live ENTITY-MIB walk of <strong><?= $t->ee( $xcvrSwitch->name ) ?></strong> — nothing has been
                saved. Review the mapping below, then <em>Save to database</em>. This is the UI equivalent of
                <code>artisan switch:detect-transceivers <?= $t->ee( $xcvrSwitch->name ) ?></code>.
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    Detected optics (<?= count( $xcvrDetected ) ?>)
                </div>
                <div class="card-body">
                    <table class="table table-sm table-striped">
                        <thead class="thead-dark">
                            <tr>
                                <th>Port</th>
                                <th>Detected transceiver</th>
                                <th>Catalogue match</th>
                                <th>Currently stored</th>
                                <th>Change?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach( $xcvrDetected as $d ):
                                $sp      = $d['port'];
                                $changed = $sp->detected_xcvr !== $d['xcvr']
                                           || (int)$sp->port_type_id !== (int)( $d['type']?->id );
                            ?>
                                <tr>
                                    <td><?= $t->ee( $sp->ifName ) ?></td>
                                    <td>
                                        <code class="tw-text-xs"><?= $t->ee( $d['xcvr'] ) ?></code>
                                        <?php if( !empty( $d['mau'] ) && $d['mau'] !== $d['xcvr'] ): ?>
                                            <br><small class="tw-text-gray-500">MAU: <code class="tw-text-xs"><?= $t->ee( $d['mau'] ) ?></code></small>
                                        <?php endif; ?>
                                        <?php if( ( $d['source'] ?? 'entity' ) === 'mau' ): ?>
                                            <span class="badge badge-secondary" title="No ENTITY-MIB entry for this port — value from the MAU MIB the core poller stores">via MAU</span>
                                        <?php elseif( ( $d['source'] ?? '' ) === 'entity+mau' ): ?>
                                            <span class="badge badge-secondary" title="ENTITY-MIB gave a vendor part number only — the media type was classified from the MAU MIB string">type via MAU</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if( $d['type'] ): ?>
                                            <?= $t->ee( $d['type']->name ) ?>
                                        <?php else: ?>
                                            <span class="badge badge-danger">no catalogue match</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if( $sp->detected_xcvr ): ?>
                                            <code class="tw-text-xs"><?= $t->ee( $sp->detected_xcvr ) ?></code>
                                        <?php else: ?>
                                            <span class="tw-text-gray-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if( $changed ): ?>
                                            <span class="badge badge-warning">will update</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">unchanged</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if( count( $xcvrUnmapped ) ): ?>
                        <div class="alert alert-warning mb-3">
                            <strong><?= count( $xcvrUnmapped ) ?> optic(s) couldn't be tied to a switch port</strong>
                            (port not in the database, or the entity names don't carry an interface name):
                            <ul class="mb-0">
                                <?php foreach( $xcvrUnmapped as $u ): ?>
                                    <li>
                                        entity <?= (int)$u['entity'] ?> — <code><?= $t->ee( $u['xcvr'] ) ?></code>
                                        <?= $u['iface'] ? ' (iface guess: ' . $t->ee( $u['iface'] ) . ')' : ' (no iface name found)' ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= route( 'switch-xcvr@apply', [ 'switch' => $xcvrSwitch->id ] ) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save to database
                        </button>
                        <small class="form-text text-muted">
                            Re-walks the switch and stores the results. Ports with no detectable optic have any
                            previously stored detection cleared. Admin overrides are never touched.
                        </small>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <a data-toggle="collapse" href="#rawEntities" role="button" aria-expanded="false" aria-controls="rawEntities">
                        Raw ENTITY-MIB rows (<?= count( $xcvrEntities ) ?>) — click to expand
                    </a>
                </div>
                <div class="collapse" id="rawEntities">
                    <div class="card-body">
                        <table class="table table-sm table-striped tw-text-xs">
                            <thead class="thead-dark">
                                <tr><th>entIdx</th><th>class</th><th>name</th><th>alias</th><th>model</th><th>descr</th><th>vendorType</th><th>containedIn</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach( $xcvrEntities as $idx => $e ): ?>
                                    <tr>
                                        <td><?= (int)$idx ?></td>
                                        <td><?= $t->ee( (string)$e['class'] ) ?></td>
                                        <td><?= $t->ee( $e['name'] ) ?></td>
                                        <td><?= $t->ee( $e['alias'] ) ?></td>
                                        <td><?= $t->ee( $e['model'] ) ?></td>
                                        <td><?= $t->ee( $e['descr'] ) ?></td>
                                        <td><?= $t->ee( $e['vendorType'] ?? '' ) ?></td>
                                        <td><?= $e['containedIn'] !== null ? (int)$e['containedIn'] : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
