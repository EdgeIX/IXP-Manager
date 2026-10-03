<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

// Foil throws on vars passed as null ("X is not defined") — the project's
// documented safe pattern is a try/catch snapshot, not `?? null`.
try { $portType = $t->portType; } catch( \RuntimeException $e ) { $portType = null; }
$editing      = (bool)$portType;
$ptLocations  = $t->ptLocations;
$ptOfferedIds = array_map( 'intval', old( 'offered_locations', $t->ptOfferedIds ) ?: [] );
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Port Types / <?= $editing ? $t->ee( $portType->name ) : 'Add' ?>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-8 mx-auto">

            <?= $t->alerts() ?>

            <?php if( $errors = session( 'errors' ) ): ?>
                <?php if( $errors->any() ): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach( $errors->all() as $error ): ?>
                                <li><?= $t->ee( $error ) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><?= $editing ? 'Edit' : 'Add' ?> port type</div>
                <div class="card-body">
                    <form method="POST" action="<?= $editing ? route( 'port-type@update', [ 'portType' => $portType->id ] ) : route( 'port-type@store' ) ?>">
                        <?= csrf_field() ?>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="name">Name</label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" id="name" name="name" maxlength="64" required
                                       value="<?= $t->ee( old( 'name', $portType?->name ) ?? '' ) ?>" placeholder="e.g. 100GBASE-LR4">
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="speed">Speed (Mbps)</label>
                            <div class="col-sm-9">
                                <input type="number" class="form-control" id="speed" name="speed" min="1" required
                                       value="<?= $t->ee( (string)( old( 'speed', $portType?->speed ) ?? '' ) ) ?>" placeholder="e.g. 100000">
                                <small class="form-text text-muted">Same convention as ifHighSpeed: 10G = 10000, 100G = 100000.</small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="priority">Match priority</label>
                            <div class="col-sm-9">
                                <input type="number" class="form-control" id="priority" name="priority" min="1" max="9999" required
                                       value="<?= $t->ee( (string)( old( 'priority', $portType?->priority ?? 100 ) ) ) ?>">
                                <small class="form-text text-muted">
                                    Lowest first. Put specific types (100G-LR4) at lower numbers than catch-alls (100G-LR)
                                    so detection never maps an LR4 optic to the LR type.
                                </small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="match_patterns">Match patterns</label>
                            <div class="col-sm-9">
                                <textarea class="form-control tw-font-mono" id="match_patterns" name="match_patterns" rows="4"
                                          placeholder="100G-?LR4&#10;100G-?BASE-?LR4"><?= $t->ee( old( 'match_patterns', $portType?->match_patterns ) ?? '' ) ?></textarea>
                                <small class="form-text text-muted">
                                    One case-insensitive regular expression per line, matched against the detected
                                    transceiver model + description (e.g. <code>QSFP-100G-LR4 Arista Networks QSFP-100G-LR4</code>).
                                    First matching type in priority order wins.
                                </small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="low_stock_threshold">Low-stock threshold</label>
                            <div class="col-sm-9">
                                <input type="number" class="form-control" id="low_stock_threshold" name="low_stock_threshold" min="0"
                                       value="<?= $t->ee( (string)( old( 'low_stock_threshold', $portType?->low_stock_threshold ) ?? '' ) ) ?>">
                                <small class="form-text text-muted">
                                    Alert admins when sellable stock of this type at any location drops below this. Blank = no alerting.
                                </small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Offered at</label>
                            <div class="col-sm-9">
                                <div class="row">
                                    <?php foreach( $ptLocations as $ptLoc ): ?>
                                        <div class="col-md-4">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="offered-<?= (int)$ptLoc->id ?>"
                                                       name="offered_locations[]" value="<?= (int)$ptLoc->id ?>"
                                                    <?= in_array( (int)$ptLoc->id, $ptOfferedIds, true ) ? 'checked' : '' ?>>
                                                <label class="custom-control-label" for="offered-<?= (int)$ptLoc->id ?>"><?= $t->ee( $ptLoc->name ) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="form-text text-muted">
                                    <strong>None ticked = offered wherever this optic type is detected</strong> (the
                                    default — right for common types). Tick sites to restrict (e.g. 400G): stock,
                                    the order form and low-stock alerts then apply ONLY at ticked sites — and the
                                    low-stock alert fires there even when the site currently has zero ports of
                                    this type.
                                </small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="notes">Notes</label>
                            <div class="col-sm-9">
                                <textarea class="form-control" id="notes" name="notes" rows="2"><?= $t->ee( old( 'notes', $portType?->notes ) ?? '' ) ?></textarea>
                            </div>
                        </div>

                        <div class="form-group row">
                            <div class="col-sm-9 offset-sm-3">
                                <div class="custom-control custom-checkbox">
                                    <input type="hidden" name="active" value="0">
                                    <input type="checkbox" class="custom-control-input" id="active" name="active" value="1"
                                        <?= old( 'active', $portType?->active ?? true ) ? 'checked' : '' ?>>
                                    <label class="custom-control-label" for="active">Active — counts as sellable stock and (later) appears on the order form. Inactive types still classify detected optics, for inventory only (e.g. core-link CWDM4, not-yet-launched 25G).</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group row mb-0">
                            <div class="col-sm-9 offset-sm-3">
                                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save' : 'Create' ?></button>
                                <a class="btn btn-white" href="<?= route( 'port-type@index' ) ?>">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
