<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );

    // Categorise core bundles by comparing infrastructure of Switch A vs Switch B
    $categories = [
        'intra'          => [],   // Same infrastructure (intra-DC / intra-city)
        'intercapital'   => [],   // Different infrastructure, same country
        'international'  => [],   // Different country
    ];

    // Group intra-infrastructure bundles by infrastructure name
    $infraGroups = [];

    foreach( $t->cbs as $cb ) {
        /** @var \IXP\Models\CoreBundle $cb */
        $switchA = $cb->switchSideX( true );
        $switchB = $cb->switchSideX( false );

        if( !$switchA || !$switchB ) {
            $categories['intra'][] = $cb;
            continue;
        }

        $infraA = $switchA->infrastructureModel;
        $infraB = $switchB->infrastructureModel;

        if( !$infraA || !$infraB ) {
            $categories['intra'][] = $cb;
            continue;
        }

        if( $infraA->id === $infraB->id ) {
            // Same infrastructure
            $key = $infraA->name;
            if( !isset( $infraGroups[ $key ] ) ) {
                $infraGroups[ $key ] = [];
            }
            $infraGroups[ $key ][] = $cb;
        } else {
            // Get country — try Infrastructure first, fall back to Location via Cabinet
            $countryA = $infraA->country
                ?? ( $switchA->cabinet->location->country ?? null );
            $countryB = $infraB->country
                ?? ( $switchB->cabinet->location->country ?? null );

            if( $countryA && $countryB && $countryA === $countryB ) {
                $categories['intercapital'][] = $cb;
            } else {
                $categories['international'][] = $cb;
            }
        }
    }

    // Sort infrastructure groups alphabetically
    ksort( $infraGroups );
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Core Bundles
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a target="_blank" class="btn btn-white" href="https://docs.ixpmanager.org/latest/features/core-bundles/">
            Documentation
        </a>
        <a id="add-cb-wizard" type="button" class="btn btn-white" href="<?= route( 'core-bundle@create-wizard' )?>">
            <i class="fa fa-plus"></i>
        </a>
    </div>
<?php $this->append() ?>

<?php $this->section('content') ?>
    <div class="row">
        <div class="col-sm-12">
            <?= $t->alerts() ?>

            <?php foreach( $infraGroups as $infraName => $bundles ): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa fa-building mr-2"></i>
                            <?= $t->ee( $infraName ) ?>
                        </h5>
                        <span class="badge badge-secondary"><?= count( $bundles ) ?> bundle<?= count( $bundles ) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php include __DIR__ . '/_cb-table.foil.php'; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if( count( $categories['intercapital'] ) > 0 ): ?>
                <?php $bundles = $categories['intercapital']; ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa fa-exchange mr-2"></i>
                            Intercapital
                        </h5>
                        <span class="badge badge-info"><?= count( $bundles ) ?> bundle<?= count( $bundles ) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php include __DIR__ . '/_cb-table.foil.php'; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if( count( $categories['international'] ) > 0 ): ?>
                <?php $bundles = $categories['international']; ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa fa-globe mr-2"></i>
                            International
                        </h5>
                        <span class="badge badge-warning"><?= count( $bundles ) ?> bundle<?= count( $bundles ) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php include __DIR__ . '/_cb-table.foil.php'; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <?= $t->insert( 'interfaces/core-bundle/js/list' ); ?>
<?php $this->append() ?>
