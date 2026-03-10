<?php
    /**
     * EdgIX skin: Port type filter — excludes service ports (no VlanInterface)
     * from the Peering Ports tab so they only appear under Service Ports.
     */
?>
<?php foreach( $t->c->virtualInterfaces as $vi ): ?>
    <?php if( $vi->type() === $t->type ): ?>
        <?php
            // For peering type: skip ports with no VlanInterface (those go to Service Ports tab)
            if( $t->type === \IXP\Models\SwitchPort::TYPE_PEERING && $vi->vlanInterfaces->isEmpty() ) {
                continue;
            }
        ?>
        <?= $t->insert( 'customer/overview-tabs/ports/port', [ 'c' => $t->c, 'vi' => $vi, 'nbVi' => $t->nbVi, 'isSuperUser' => $t->isSuperUser ] ); ?>
        <?php $t->nbVi++ ?>
    <?php endif;?>
<?php endforeach; ?>
