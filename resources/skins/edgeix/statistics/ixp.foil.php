<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' )
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <?= config( 'identity.orgname' ) ?> Public Traffic Statistics
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-md-12">
            <?= $t->alerts() ?>
            <nav id="filter-row" class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                <span class="navbar-brand">Graph Options:</span>
                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNavDropdown">
                    <div class="form-inline">
                        <label for="form-select-category" class="mr-2">Category:</label>
                        <select id="form-select-category" name="category" class="form-control mr-3">
                            <?php foreach( IXP\Services\Grapher\Graph::CATEGORIES_BITS_PKTS_DESCS as $cvalue => $cname ): ?>
                                <option value="<?= $cvalue ?>" <?= $t->category !== $cvalue ?: 'selected="selected"' ?>><?= $cname ?></option>
                            <?php endforeach; ?>
                        </select>

                        <a class="btn btn-white" href="<?= route( 'statistics@infrastructure' ) ?>">
                            Per-Infrastructure Graphs
                        </a>
                    </div>
                </div>
            </nav>

            <div class="row">
                <?php foreach( IXP\Services\Grapher\Graph::PERIODS as $pvalue => $pname ): ?>
                    <div class="col-lg-6 col-md-12">
                        <div class="card mb-4">
                            <div class="card-header d-flex py-2">
                                <h5 class="mb-0 mr-auto"><?= IXP\Services\Grapher\Graph::resolvePeriod( $pvalue ) ?> Graph</h5>
                            </div>
                            <div class="card-body py-2">
                                <?= $t->graph->setPeriod( $pvalue )->renderer()->boxUplot() ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <script>
        let base_route   = "<?= route( 'statistics@ixp' ) ?>";
        let sel_category = $("#form-select-category");

        function changeGraph() {
            window.location = `${base_route}/${sel_category.val()}`;
        }

        sel_category.on( 'change', changeGraph );
    </script>

<?php $this->append() ?>
