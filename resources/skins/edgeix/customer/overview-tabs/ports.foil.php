<?php
    /**
     * EdgIX skin: Customer overview — Ports tab (non-reseller customers).
     *
     * Replaces the upstream flat list with a tabbed layout that separates
     * peering ports from service-only ports (no VlanInterface — used for
     * pseudowires, dedicated resold, or other non-peering purposes).
     */

    $peeringPorts = [];
    $servicePorts = [];

    foreach( $t->c->virtualInterfaces as $vi ) {
        // Skip core port types (covers core bundles regardless of getCoreBundle result)
        $viType = $vi->type();
        if( $viType === \IXP\Models\SwitchPort::TYPE_CORE
            || $viType === \IXP\Models\SwitchPort::TYPE_MONITOR
            || $viType === \IXP\Models\SwitchPort::TYPE_MANAGEMENT ) {
            continue;
        }

        if( $vi->vlanInterfaces->isEmpty() ) {
            $servicePorts[] = $vi;
        } else {
            $peeringPorts[] = $vi;
        }
    }
?>

<?php if( $t->isSuperUser && !$t->c->statusNormal() ): ?>
    <div class="alert alert-danger" role="alert">
        <b>Warning! Customer status is not normal.</b>
        Many backend processes that configure interface related systems (for example
        MRTG, P2P statistics, Nagios, Smokeping, route collector, route servers, etc.)
        will skip members that do not have their customer status set to normal.
    </div>
<?php endif; ?>

<?php if( count( $servicePorts ) > 0 ): ?>
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-pills card-header-pills">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="tab" href="#peering">
                        Peering Ports
                        <span class="badge badge-light"><?= count( $peeringPorts ) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#service">
                        Service Ports
                        <span class="badge badge-light"><?= count( $servicePorts ) ?></span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content mt-4">
                <div id="peering" class="tab-pane fade show active">
                    <div class="d-flex row">
                        <?php $nbVi = 1 ?>
                        <?php foreach( $peeringPorts as $vi ): ?>
                            <?= $t->insert( 'customer/overview-tabs/ports/port', [ 'c' => $t->c, 'vi' => $vi, 'nbVi' => $nbVi, 'isSuperUser' => $t->isSuperUser ] ); ?>
                            <?php $nbVi++ ?>
                        <?php endforeach; ?>
                        <?php if( empty( $peeringPorts ) ): ?>
                            <div class="col-12">
                                <p class="text-muted">No peering ports configured.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="service" class="tab-pane fade">
                    <div class="d-flex row">
                        <?php $nbVi = 1 ?>
                        <?php foreach( $servicePorts as $vi ): ?>
                            <?= $t->insert( 'customer/overview-tabs/ports/port', [ 'c' => $t->c, 'vi' => $vi, 'nbVi' => $nbVi, 'isSuperUser' => $t->isSuperUser ] ); ?>
                            <?php $nbVi++ ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex row">
        <?php $nbVi = 1 ?>
        <?php foreach( $peeringPorts as $vi ): ?>
            <?= $t->insert( 'customer/overview-tabs/ports/port', [ 'c' => $t->c, 'vi' => $vi, 'nbVi' => $nbVi, 'isSuperUser' => $t->isSuperUser ] ); ?>
            <?php $nbVi++ ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
