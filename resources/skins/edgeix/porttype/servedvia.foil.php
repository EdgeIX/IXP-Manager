<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

$svLocations = $t->svLocations;

// Demarc candidates = sites that are not themselves served-via.
$svDemarcs = $svLocations->filter( fn( $l ) => !$l->served_via_locationid );
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <a href="<?= route( 'port-stock@index' ) ?>">Port Stock</a> / Served-via Sites
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-8 mx-auto">

            <?= $t->alerts() ?>

            <div class="card">
                <div class="card-header">Served-via mapping</div>
                <div class="card-body">
                    <p class="tw-text-sm tw-text-gray-600">
                        Mark passive/campus sites as <em>served via</em> a demarc site (e.g. Equinix
                        SY3/SY4/SY5 via Equinix SY1/SY2). Customers at those sites then see them on the
                        order form — drawing on the demarc site's stock, with the order and LOA resolving
                        to the demarc and the customer told to arrange the x-connect from their site.
                        Leave blank for normal active sites.
                    </p>

                    <form method="POST" action="<?= route( 'port-stock@save-served-via' ) ?>">
                        <?= csrf_field() ?>

                        <table class="table table-sm table-striped">
                            <thead class="thead-dark">
                                <tr><th>Site</th><th>Served via</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach( $svLocations as $svLoc ): ?>
                                    <tr>
                                        <td class="align-middle"><?= $t->ee( $svLoc->name ) ?></td>
                                        <td>
                                            <select class="form-control form-control-sm" name="served[<?= (int)$svLoc->id ?>]">
                                                <option value="">— active site (not served-via) —</option>
                                                <?php foreach( $svDemarcs as $d ): ?>
                                                    <?php if( $d->id === $svLoc->id ) continue; ?>
                                                    <option value="<?= (int)$d->id ?>" <?= (int)$svLoc->served_via_locationid === (int)$d->id ? 'selected' : '' ?>>
                                                        <?= $t->ee( $d->name ) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <button type="submit" class="btn btn-primary">Save mapping</button>
                    </form>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
