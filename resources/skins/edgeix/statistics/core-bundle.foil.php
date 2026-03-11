<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );

    $cb          = $t->cb;
    $isSuperUser = Auth::check() && Auth::getUser()->isSuperUser();
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <?= $t->ee( $cb->graph_title ) ?>
    (<?= IXP\Services\Grapher\Graph::resolveCategory( $t->category ) ?>)
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-md-12">
            <?= $t->alerts() ?>

            <nav id="filter-row" class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                <span class="navbar-brand">Core Bundle:</span>

                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarNavDropdown">
                    <div class="form-inline">
                        <select id="form-select-corebundleid" name="cbid" class="form-control mr-3">
                            <?php foreach( $t->cbs as $cbl ): ?>
                                <option value="<?= $cbl->id ?>" <?= $cb->id !== $cbl->id ?: 'selected="selected"' ?>>
                                    <?= $cbl->graph_title ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="form-select-side" class="mr-2">Side:</label>
                        <select id="form-select-side" name="side" class="form-control mr-3">
                            <?php foreach( [ 'a' => 'A', 'b' => 'B' ] as $svalue => $sname ): ?>
                                <option value="<?= $svalue ?>" <?= $t->graph->side() !== $svalue ?: 'selected="selected"' ?>><?= $sname ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="form-select-category" class="mr-2">Category:</label>
                        <select id="form-select-category" name="category" class="form-control mr-3">
                            <?php foreach( $t->categories as $cvalue => $cname ): ?>
                                <option value="<?= $cvalue ?>" <?= $t->category !== $cvalue ?: 'selected="selected"' ?>><?= $cname ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="pull-right tw-text-gray-600">
                    <?= $cb->typeText() ?>,
                    <?= $t->ee( $cb->switchSideX( $t->graph->side() === 'a' )->name ) ?> -
                    <?= $t->ee( $cb->switchSideX( $t->graph->side() !== 'a' )->name ) ?>,

                    <?php if( $nb = $cb->coreLinks()->count() ): ?>
                        <?= $nb ?> x <?= $t->scaleBits( $cb->speedPi() * 1000000, 0 ) ?>
                        = <?= $t->scaleBits( $nb * $cb->speedPi() * 1000000, 0 ) ?>
                    <?php else: ?>
                        <?= $t->scaleBits( $cb->speedPi() * 1000000, 0 ) ?>
                    <?php endif ?>

                    <?php if( $t->category === 'bits' ):
                        try {
                            $cbStats = $t->graph->setCategory( 'bits' )->setPeriod( 'day' )->statistics();
                            $cbCapBps = ( $nb ?: 1 ) * $cb->speedPi() * 1000000;
                            $cbUtilPct = $cbCapBps > 0 ? max( $cbStats->curIn(), $cbStats->curOut() ) / $cbCapBps * 100 : 0;
                        ?>
                            <span class="badge badge-<?= $cbUtilPct > 80 ? 'danger' : ( $cbUtilPct > 50 ? 'warning' : 'success' ) ?> ml-2" title="Bundle utilization">
                                <?= number_format( $cbUtilPct, 1 ) ?>%
                            </span>
                        <?php } catch( \Throwable $e ) {} ?>
                    <?php endif; ?>
                </div>
            </nav>

            <!-- Aggregate Graph -->
            <div class="row">
                <?php foreach( IXP\Services\Grapher\Graph::PERIODS as $pvalue => $pname ): ?>
                    <div class="col-md-12 col-lg-6 mb-4">
                        <div class="card">
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

            <?php
                // Per-member link breakdown
                $side      = $t->graph->side();
                $corelinks = $cb->corelinks()->with([
                    'coreInterfaceSideA.physicalInterface.switchPort.switcher',
                    'coreInterfaceSideB.physicalInterface.switchPort.switcher',
                ])->get();

                $memberGraphs = [];
                foreach( $corelinks as $cl ) {
                    $ci = $side === 'a' ? $cl->coreInterfaceSideA : $cl->coreInterfaceSideB;
                    $pi = $ci->physicalInterface;
                    if( $pi && $pi->switchPort && $pi->switchPort->switcher ) {
                        $memberGraphs[] = [
                            'pi'    => $pi,
                            'label' => $pi->switchPort->switcher->name . ':' . $pi->switchPort->name,
                        ];
                    }
                }
            ?>

            <?php if( count( $memberGraphs ) > 1 ): ?>
                <h4 class="mt-4 mb-3">Individual Links (Side <?= strtoupper( $side ) ?>)</h4>

                <?php foreach( $memberGraphs as $mg ): ?>
                    <?php
                        $piGraph = App::make( IXP\Services\Grapher::class )
                            ->physint( $mg['pi'] )
                            ->setCategory( $t->category );
                    ?>
                    <div class="card mb-4">
                        <div class="card-header py-2">
                            <h5 class="mb-0"><?= $mg['label'] ?></h5>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <?php foreach( IXP\Services\Grapher\Graph::PERIODS as $pvalue => $pname ): ?>
                                    <div class="col-md-12 col-lg-6 mb-3">
                                        <h6 class="text-muted"><?= IXP\Services\Grapher\Graph::resolvePeriod( $pvalue ) ?></h6>
                                        <?= $piGraph->setPeriod( $pvalue )->renderer()->boxUplot() ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if( $t->category === 'bits' ):
                                try {
                                    $vmBackend = app( \IXP\Services\Grapher\Backend\VictoriaMetrics::class );
                                    $domData = $vmBackend->domData( $mg['pi'], 'day' );
                                    echo $t->insert( 'services/grapher/renderer/box/dom', [
                                        'domData'   => $domData,
                                        'domLabel'  => $mg['label'],
                                        'domPeriod' => 'day',
                                    ]);
                                } catch( \Throwable $e ) {}
                            endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <script>
        let base_route     = "<?= url( '' ) ?>/statistics/core-bundle";
        let sel_corebundle = $("#form-select-corebundleid");
        let sel_category   = $("#form-select-category");
        let sel_side       = $("#form-select-side");

        function changeGraph() {
            window.location = `${base_route}/${sel_corebundle.val()}?category=${sel_category.val()}&side=${sel_side.val()}`;
        }

        sel_corebundle.on( 'change', changeGraph );
        sel_category.on(   'change', changeGraph );
        sel_side.on(       'change', changeGraph );
    </script>
<?php $this->append() ?>
