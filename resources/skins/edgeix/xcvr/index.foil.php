<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

use IXP\Models\SwitchPort;

$xcvrSwitch    = $t->xcvrSwitch;
$xcvrPorts     = $t->xcvrPorts;
$xcvrPortTypes = $t->xcvrPortTypes;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Port Transceivers / <?= $t->ee( $xcvrSwitch->name ) ?>
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a class="btn btn-white" href="<?= route( 'switch-xcvr@detect', [ 'switch' => $xcvrSwitch->id ] ) ?>">
            <i class="fa fa-refresh"></i> Detect Now (live SNMP)
        </a>
        <a class="btn btn-white" href="<?= route( 'port-type@index' ) ?>">
            <i class="fa fa-list"></i> Port Types
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

            <div class="card">
                <div class="card-header">
                    Stored transceiver detection for <?= $t->ee( $xcvrSwitch->name ) ?> — the
                    <em>effective type</em> (override if set, else detected) is what Port Stock and the
                    order form count. Last detection run is per-port below; cron
                    <code>switch:detect-transceivers</code> keeps this current.
                </div>
                <div class="card-body">
                    <table class="table table-sm table-striped">
                        <thead class="thead-dark">
                            <tr>
                                <th>Port</th>
                                <th>Use</th>
                                <th>Detected optic</th>
                                <th>Detected type</th>
                                <th>Override</th>
                                <th>Effective type</th>
                                <th>In service?</th>
                                <th>Detected at</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach( $xcvrPorts as $sp ):
                                $effective = $sp->effectivePortType();
                            ?>
                                <tr>
                                    <td><?= $t->ee( $sp->ifName ) ?></td>
                                    <td><?= $t->ee( SwitchPort::$TYPES[ $sp->type ] ?? 'Unknown' ) ?></td>
                                    <td>
                                        <?php if( $sp->detected_xcvr ): ?>
                                            <code class="tw-text-xs"><?= $t->ee( $sp->detected_xcvr ) ?></code>
                                        <?php else: ?>
                                            <span class="tw-text-gray-400">none detected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $sp->portType ? $t->ee( $sp->portType->name ) : '—' ?></td>
                                    <td>
                                        <form method="POST" action="<?= route( 'switch-xcvr@set-override', [ 'sp' => $sp->id ] ) ?>" class="form-inline">
                                            <?= csrf_field() ?>
                                            <select name="port_type_override_id" class="form-control form-control-sm tw-mr-1">
                                                <option value="">— none (use detection) —</option>
                                                <?php foreach( $xcvrPortTypes as $pt ): ?>
                                                    <option value="<?= $pt->id ?>" <?= (int)$sp->port_type_override_id === (int)$pt->id ? 'selected' : '' ?>>
                                                        <?= $t->ee( $pt->name ) ?><?= $pt->active ? '' : ' (inactive)' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-white btn-sm" title="Save override"><i class="fa fa-check"></i></button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if( $effective ): ?>
                                            <strong><?= $t->ee( $effective->name ) ?></strong>
                                            <?php if( $sp->port_type_override_id ): ?>
                                                <span class="badge badge-info">override</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="tw-text-gray-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if( $sp->physicalInterface ): ?>
                                            <span class="badge badge-secondary">in service</span>
                                        <?php elseif( $sp->patchPanelPort ): ?>
                                            <?= $t->ee( \IXP\Models\PatchPanelPort::$STATES[ $sp->patchPanelPort->state ] ?? (string)$sp->patchPanelPort->state ) ?>
                                        <?php else: ?>
                                            <span class="tw-text-gray-400">free, no panel port</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tw-text-xs"><?= $t->ee( (string)( $sp->detected_xcvr_at ?? '—' ) ) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
