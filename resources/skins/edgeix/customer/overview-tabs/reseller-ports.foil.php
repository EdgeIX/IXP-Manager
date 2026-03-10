<?php
    /**
     * EdgIX skin: Customer overview — Ports tab (reseller customers).
     *
     * Extends upstream with a "Service Ports" tab for ports without VlanInterface
     * (used for pseudowires, dedicated services, or other non-peering purposes).
     */

    $hasServicePorts = false;
    foreach( $t->c->virtualInterfaces as $vi ) {
        if( $vi->vlanInterfaces->isEmpty() && $vi->type() === \IXP\Models\SwitchPort::TYPE_PEERING ) {
            $hasServicePorts = true;
            break;
        }
    }
?>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-pills card-header-pills">
            <li class="nav-item">
                <a class="nav-link active" data-toggle="tab" href="#peering">
                    Peering Ports
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#reseller">
                    Reseller Uplink Ports
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#fanout">
                    Fanout Ports
                </a>
            </li>
            <?php if( $hasServicePorts ): ?>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#service">
                        Service Ports
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="card-body">
        <?php $nbVi = 1 ?>
        <div class="tab-content mt-4">
            <div id="peering" class="tab-pane fade show active">
                <?= $t->insert( 'customer/overview-tabs/ports/port-type', [ 'nbVi' => $nbVi, 'type' => \IXP\Models\SwitchPort::TYPE_PEERING, 'isSuperUser' => $t->isSuperUser ] ); ?>
            </div>
            <div id="reseller" class="tab-pane fade">
                <?= $t->insert( 'customer/overview-tabs/ports/port-type', [ 'nbVi' => $nbVi, 'type' => \IXP\Models\SwitchPort::TYPE_RESELLER, 'isSuperUser' => $t->isSuperUser ] ); ?>
            </div>
            <div id="fanout" class="tab-pane fade">
                <?= $t->insert( 'customer/overview-tabs/ports/port-type', [ 'nbVi' => $nbVi, 'type' => \IXP\Models\SwitchPort::TYPE_FANOUT, 'isSuperUser' => $t->isSuperUser ] ); ?>
            </div>
            <?php if( $hasServicePorts ): ?>
                <div id="service" class="tab-pane fade">
                    <div class="d-flex row">
                        <?php foreach( $t->c->virtualInterfaces as $vi ): ?>
                            <?php if( $vi->vlanInterfaces->isEmpty() && $vi->type() === \IXP\Models\SwitchPort::TYPE_PEERING ): ?>
                                <?= $t->insert( 'customer/overview-tabs/ports/port', [ 'c' => $t->c, 'vi' => $vi, 'nbVi' => $nbVi, 'isSuperUser' => $t->isSuperUser ] ); ?>
                                <?php $nbVi++ ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
