<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' )
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Statistics / Graphs
    <?php if( $t->graph ): ?>

        <?php if( !(Auth::check() && Auth::getUser()->isSuperUser() ) ): ?>
            <small>
        <?php endif; ?>

        (
        <?= $t->infra ? 'MRTG: '  . $t->infra->name : '' ?>
        <?= $t->vlan  ? 'SFlow: ' . $t->vlan->name  : '' ?>
        /
        <?= $t->graph->resolveCategory( $t->graph->category() ) ?>
        /
        <?= $t->graph->resolvePeriod( $t->graph->period() ) ?>
        <?php if( $t->graph->protocol() !== IXP\Services\Grapher\Graph::PROTOCOL_ALL ): ?>
            /
            <?= $t->graph->resolveProtocol( $t->graph->protocol() ) ?>
        <?php endif; ?>
        )

        <?php if( !(Auth::check() && Auth::getUser()->isSuperUser() ) ): ?>
            </small>
        <?php endif; ?>
    <?php endif; ?>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-sm-12">
            <?php if( in_array( 'mrtg', config( 'grapher.backend' ), true ) || in_array( 'victoriametrics', config( 'grapher.backend' ), true ) ): ?>
                <form method="post" action="<?= route('statistics@members' ) ?>">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <nav id="filter-row" class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                        <span class="navbar-brand">Infrastructure:</span>

                        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                            <span class="navbar-toggler-icon"></span>
                        </button>

                        <div class="collapse navbar-collapse" id="navbarNavDropdown">
                            <div class="form-inline">
                                <label for="selectInfra" class="mr-2">Infrastructure:</label>
                                <select id="selectInfra" class="form-control mr-3" name="infra">
                                    <option>All</option>
                                    <?php foreach( $t->infras as $i ): ?>
                                        <option value="<?= $i[ 'id' ] ?>" <?= $t->infra && $t->infra->id === $i[ 'id' ] ? 'selected="selected"' : '' ?>><?= $i[ 'name' ] ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="selectCategory" class="mr-2">Category:</label>
                                <select id="selectCategory" class="form-control mr-3" name="category">
                                    <?php foreach( IXP\Services\Grapher\Graph::CATEGORY_DESCS as $c => $d ): ?>
                                        <option value="<?= $c ?>" <?= $t->r->category === $c ? 'selected="selected"' : '' ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="selectPeriod" class="mr-2">Period:</label>
                                <select id="selectPeriod" class="form-control mr-3" name="period">
                                    <?php foreach( IXP\Services\Grapher\Graph::PERIOD_DESCS as $p => $d ): ?>
                                        <option value="<?= $p ?>" <?= $t->r->period === $p ? 'selected="selected"' : '' ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <input class="btn btn-white" type="submit" name="submit" value="Show Graphs" />
                            </div>
                        </div>
                    </nav>
                </form>
            <?php endif; ?>

            <?php if( in_array( 'sflow', config( 'grapher.backend' ), true ) ): ?>
                <form method="post" action="<?= route('statistics@members' ) ?>">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm">
                        <span class="navbar-brand">SFlow:</span>

                        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown2" aria-controls="navbarTogglerDemo01" aria-expanded="false" aria-label="Toggle navigation">
                            <span class="navbar-toggler-icon"></span>
                        </button>

                        <div class="collapse navbar-collapse" id="navbarNavDropdown2">
                            <div class="form-inline">
                                <label for="selectVlan" class="mr-2">VLAN:</label>
                                <select id="selectVlan" class="form-control mr-3" name="vlan">
                                    <option>All</option>
                                    <?php foreach( $t->vlans as $i ): ?>
                                        <option value="<?= $i[ 'id' ] ?>" <?= $t->vlan && $t->vlan->id === $i[ 'id' ] ? 'selected="selected"' : '' ?>><?= $i[ 'name' ] ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="selectProtocol" class="mr-2">Protocol:</label>
                                <select id="selectProtocol" class="form-control mr-3" name="protocol">
                                    <option>All</option>
                                    <?php foreach( \IXP\Services\Grapher\Graph::PROTOCOL_REAL_DESCS as $p => $n ): ?>
                                        <option value="<?= $p ?>" <?= $t->r->protocol === $p ? 'selected="selected"' : '' ?>><?= $n ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="selectCategory2" class="mr-2">Category:</label>
                                <select id="selectCategory2" class="form-control mr-3" name="category">
                                    <?php foreach( IXP\Services\Grapher\Graph::CATEGORY_DESCS as $c => $d ): ?>
                                        <option value="<?= $c ?>" <?= $t->r->category === $c ? 'selected="selected"' : '' ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="selectPeriod2" class="mr-2">Period:</label>
                                <select id="selectPeriod2" class="form-control mr-3" name="period">
                                    <?php foreach( IXP\Services\Grapher\Graph::PERIOD_DESCS as $p => $d ): ?>
                                        <option value="<?= $p ?>" <?= $t->r->period === $p ? 'selected="selected"' : '' ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <input class="btn btn-white" type="submit" name="submit" value="Show Graphs" />
                            </div>
                        </div>
                    </nav>
                </form>
            <?php endif; ?>

            <?php if( !sizeof( array_intersect( ['mrtg', 'sflow', 'victoriametrics'], config( 'grapher.backend' ) ) ) ): ?>

                <div id="infra_reg_banner" class="tw-bg-blue-100 tw-border-l-4 tw-border-blue-500 tw-text-blue-700 p-4 alert-dismissible mb-4" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="text-center"><i class="fa fa-info-circle fa-2x "></i></div>
                        <div class="col-sm-12">
                            You must have a grapher backend (MRTG, sflow, or VictoriaMetrics) configured for this functionality.
                        </div>
                    </div>
                </div>

            <?php endif; ?>


    <?php if( !$t->graph ): ?>
                <div class="alert alert-info mt-4" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="text-center">
                            <i class="fa fa-info-circle fa-2x"></i>
                        </div>
                        <div class="col-sm-12">
                            <?php if( !$t->infra && !$t->vlan  ): ?>
                                Select parameters above and click <em>Show Graphs</em>.
                            <?php else: ?>
                                No graphs found for the requested parameters.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach( $t->graphs as $graph ): ?>
                        <div class="col-sm-12 col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header d-flex py-2">
                                    <div class="mr-auto">
                                        <b class="align-middle">
                                            <?= $graph->customer()->getFormattedName() ?>
                                        </b>
                                    </div>
                                    <div class="btn-group btn-group-sm my-auto" role="group">
                                        <?php if( config('grapher.backends.sflow.enabled') && isset( IXP\Services\Grapher\Graph::CATEGORIES_BITS_PKTS[$graph->category()] ) && $t->grapher()->canAccessAllCustomerP2pGraphs() ): ?>
                                            <a class="btn btn-white" href="<?= route('statistics@p2ps-get', [ 'cust' => $graph->customer()->id ] ) . "?category={$graph->category()}&period={$graph->period()}" ?>">
                                                <span class="fa fa-random"></span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if( $t->grapher()->canAccessAllCustomerGraphs() ): ?>
                                            <a class="btn btn-white" href="<?= route( 'statistics@member', [ $graph->customer()->id ] ) ?>">
                                                <span class="fa fa-search-plus"></span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body py-2">
                                    <?php $graph->authorise() ?>
                                    <?= $graph->renderer()->boxUplot() ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php $this->append() ?>
