<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );
    $isSuperUser = Auth::check() && Auth::getUser()->isSuperUser();
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <?php if( $isSuperUser ): ?>
        <a href="<?= route( 'customer@overview', [ 'cust' => $t->c->id ] )?>" >
            <?= $t->c->getFormattedName() ?>
        </a>
    <?php else: ?>
        IXP Port Graphs :: <?= $t->c->getFormattedName() ?>
    <?php endif; ?>

    / Statistics
    (
        <?= IXP\Services\Grapher\Graph::resolveCategory( $t->category ) ?>
        /
        <?= IXP\Services\Grapher\Graph::resolvePeriod( $t->period ) ?>
    )
<?php $this->append() ?>

<?php $this->section('content') ?>
    <div class="row">
        <div class="col-sm-12">
            <?= $t->alerts() ?>
            <form action="<?= route( "statistics@member", [ "cust" => $t->c->id ] ) ?>" method="GET">
                <nav id="filter-row" class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                    <span class="navbar-brand">Graph Options:</span>

                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="collapse navbar-collapse" id="navbarNavDropdown">
                        <div class="form-inline">
                            <label for="category" class="mr-2">Type:</label>
                            <select id="category" name="category" onchange="this.form.submit()" class="form-control mr-3">
                                <?php foreach( IXP\Services\Grapher\Graph::CATEGORY_DESCS as $cvalue => $cname ): ?>
                                    <option value="<?= $cvalue ?>" <?php if( $t->category === $cvalue ): ?> selected <?php endif; ?>><?= $cname ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label for="period" class="mr-2">Period:</label>
                            <select id="period" name="period" class="form-control mr-3">
                                <?php foreach( IXP\Services\Grapher\Graph::PERIOD_DESCS as $pvalue => $pname ): ?>
                                    <option value="<?= $pvalue ?>" <?php if( $t->period === $pvalue ): ?> selected <?php endif; ?>><?= $pname ?></option>
                                <?php endforeach; ?>
                            </select>

                            <input type="submit" class="btn btn-white mr-2" value="Show Graphs">

                            <?php if( config('grapher.backends.sflow.enabled') && $t->grapher()->canAccessAllCustomerP2pGraphs() ): ?>
                                <a class="btn btn-white" href="<?= route( 'statistics@p2ps-get', [ 'customer' => $t->c->id ] ) ?>">
                                    <i class="fa fa-random"></i>&nbsp;&nbsp;P2P Graphs
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </nav>
            </form>

            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header d-flex py-2">
                            <h5 class="mb-0 mr-auto">
                                Aggregate Peering <?= IXP\Services\Grapher\Graph::resolveCategory( $t->category ) ?>
                                <?php if( $t->resellerMode() && $t->c->isReseller ): ?>
                                    <small><em>(Peering ports only)</em></small>
                                <?php endif; ?>
                            </h5>
                            <div class="btn-group btn-group-sm my-auto">
                                <?php if( config( 'grapher.backends.sflow.enabled' ) ): ?>
                                    <a class="btn btn-sm btn-white" href="<?= route( 'statistics@p2ps-get', [ 'customer' => $t->c->id ] ) ?>">
                                        <span class="fa fa-random"></span>
                                    </a>
                                <?php endif; ?>
                                <a class="btn btn-white" href="<?= route( "statistics@member-drilldown" , [ "typeid" => $t->c->id, "type" => "agg" ] ) ?>/?category=<?= $t->category ?>">
                                    <i class="fa fa-search-plus"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <?= $t->grapher->customer( $t->c )->setCategory( $t->category )->setPeriod( $t->period )->renderer()->boxUplot() ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php foreach( $t->c->virtualinterfaces as $vi ):
                /** @var $vi \IXP\Models\VirtualInterface */?>
                <?php
                    $pis = $vi->physicalInterfaces;
                    if( $pis->isEmpty() ) { continue; }
                    $pi = $pis[ 0 ];
                    $isLAG = count( $pis ) > 1;
                ?>

                <?php if( $isLAG ): ?>
                    <?php // ── LAG: aggregate full-width, member ports in 2-col grid ── ?>
                    <div class="card mb-4">
                        <div class="card-header d-flex py-2" style="background-color: #f8f9fa;">
                            <h5 class="mb-0 mr-auto">
                                LAG on <?= $pi->switchPort->switcher->cabinet->location->name ?>
                                / <?= $pi->switchPort->switcher->name ?>
                            </h5>
                            <?php if( $vi->isGraphable() ): ?>
                                <div class="btn-group btn-group-sm my-auto">
                                    <?= $t->insert( 'statistics/snippets/latency-dropup', [ 'vi' => $vi ] ) ?>

                                    <?php if( config( 'grapher.backends.sflow.enabled' ) ): ?>
                                        <a class="btn btn-white btn-sm py-0" href="<?= route( 'statistics@p2ps-get', [ 'customer' => $t->c->id ] )
                                            . ( $vi->vlanInterfaces->isNotEmpty() ? '?svli=' . $vi->vlanInterfaces[ 0 ]->id : '' )
                                        ?>">
                                            <span class="fa fa-random"></span>
                                        </a>
                                    <?php endif; ?>

                                    <a class="btn btn-white btn-sm py-0" href="<?= route( "statistics@member-drilldown" , [ "type" => "vi", "typeid" => $vi->id  ] ) ?>/?category=<?= $t->category ?>" title="Drilldown">
                                        <i class="fa fa-search-plus"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body py-2">
                            <?php if( $vi->isGraphable() ): ?>
                                <?= $t->grapher->virtint( $vi )->setCategory( $t->category )->setPeriod( $t->period )->renderer()->boxUplot() ?>
                            <?php endif; ?>

                            <div class="row mt-3">
                                <?php foreach( $pis as $idx => $pi ): ?>
                                    <div class="col-sm-12 col-lg-6 mb-3">
                                        <div class="card border">
                                            <div class="card-header d-flex py-2 bg-white">
                                                <h6 class="mb-0 mr-auto">
                                                    <?= $pi->switchPort->switcher->name ?> ::
                                                    <?= $pi->switchPort->name ?> (<?= $t->scaleSpeed( $pi->configuredSpeed() ) . ( $pi->isRateLimited() ? '/' . $pi->speed() : '' ) ?>)
                                                </h6>
                                                <?php if( $pi->isConnectedOrQuarantine() ): ?>
                                                    <div class="btn-group btn-group-sm my-auto">
                                                        <a class="btn btn-white btn-sm py-0" href="<?= route( "statistics@member-drilldown" , [ "type" => "pi", "typeid" => $pi->id  ] ) ?>/?category=<?= $t->category ?>">
                                                            <i class="fa fa-search-plus"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-body py-2">
                                                <?php if( $pi->isConnectedOrQuarantine() ): ?>
                                                    <?= $t->grapher->physint( $pi )->setCategory( $t->category )->setPeriod( $t->period )->renderer()->boxUplot() ?>
                                                <?php else: ?>
                                                    <?= $t->insert( 'customer/overview-tabs/ports/pi-status', [ 'pi' => $pi, 'isSuperUser' => $isSuperUser ] ) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <?php // ── Single port (not LAG): full-width ── ?>
                    <div class="card mb-4">
                        <div class="card-header d-flex py-2" style="background-color: #f8f9fa;">
                            <h5 class="mb-0 mr-auto">
                                <?= $pi->switchPort->switcher->cabinet->location->name ?>
                                / <?= $pi->switchPort->switcher->name ?>
                                :: <?= $pi->switchPort->name ?> (<?= $pi->speed() ?>)

                                <?php if( $t->resellerMode() && $t->c->isReseller ): ?>
                                    <small class="text-muted">
                                        <?php if( $pi->switchPort->typePeering() ): ?>
                                            &mdash; Peering Port
                                        <?php elseif( $pi->switchPort->typeFanout() ):
                                            $cust = $pi->relatedInterface()->virtualInterface->customer; ?>
                                            &mdash; Fanout for <a href="<?= route( 'customer@overview', [ 'cust' => $cust->id ] ) ?>">
                                                <?= $cust->abbreviatedName ?>
                                            </a>
                                        <?php elseif( $pi->switchPort->typeReseller() ): ?>
                                            &mdash; Reseller Uplink
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </h5>
                            <?php if( $pi->isConnectedOrQuarantine() ): ?>
                                <div class="btn-group btn-group-sm my-auto">
                                    <?= $t->insert( 'statistics/snippets/latency-dropup', [ 'vi' => $vi ] ) ?>

                                    <?php if( config( 'grapher.backends.sflow.enabled' ) ): ?>
                                        <a class="btn btn-white btn-sm py-0" href="<?= route( 'statistics@p2ps-get', [ 'customer' => $t->c->id ] )
                                        . ( $vi->vlanInterfaces->isNotEmpty() ? '?svli=' . $vi->vlanInterfaces[ 0 ]->id : '' )
                                        ?>">
                                            <span class="fa fa-random"></span>
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-white btn-sm py-0" href="<?= route( "statistics@member-drilldown" , [ "type" => "pi", "typeid" => $pi->id  ] ) ?>/?category=<?= $t->category ?>">
                                        <i class="fa fa-search-plus"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body py-2">
                            <?php if( $pi->isConnectedOrQuarantine() ): ?>
                                <?= $t->grapher->physint( $pi )->setCategory( $t->category )->setPeriod( $t->period )->renderer()->boxUplot() ?>
                            <?php else: ?>
                                <?= $t->insert( 'customer/overview-tabs/ports/pi-status', [ 'pi' => $pi, 'isSuperUser' => $isSuperUser ] ) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php $this->append() ?>
