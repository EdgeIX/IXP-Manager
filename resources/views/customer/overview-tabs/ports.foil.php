<div class="d-flex row">
    <?php if( $t->isSuperUser && !$t->c->statusNormal() ): ?>
        <div class="alert alert-danger" role="alert">
            <b>Warning! Customer status is not normal.</b>
            Many backend processes that configure interface related systems (for example
            MRTG, P2P statistics, Nagios, Smokeping, route collector, route servers, etc.)
            will skip members that do not have their customer status set to normal.
        </div>
    <?php endif; ?>

    <?php $nbVi = 1 ?>
    <?php foreach( $t->c->virtualInterfaces as $vi ): ?>
        <?php
            // Skip core bundles, monitor, and management ports
            $viType = $vi->type();
            if( $viType === \IXP\Models\SwitchPort::TYPE_CORE
                || $viType === \IXP\Models\SwitchPort::TYPE_MONITOR
                || $viType === \IXP\Models\SwitchPort::TYPE_MANAGEMENT ) {
                continue;
            }
        ?>
        <?= $t->insert( 'customer/overview-tabs/ports/port', [ 'c' => $t->c ,'vi' => $vi, 'nbVi' => $nbVi, 'isSuperUser' => $t->isSuperUser ] ); ?>
        <?php $nbVi++ ?>
    <?php endforeach; ?>
</div>