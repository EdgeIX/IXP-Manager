<?php
    /**
     * EdgIX skin: Single connection (virtual interface) on the Ports tab.
     *
     * Full-width layout: connection details in a bordered card, graphs below.
     */

    $vlis       = $t->vi->vlanInterfaces;
    $vli        = $vlis[ 0 ] ?? 0 /** @var $vli \IXP\Models\VlanInterface */;
    $pis        = $t->vi->physicalInterfaces;
    $countPis   = $pis->count();
    $firstPi    = $pis[ 0 ] ?? 0 /** @var $firstPi \IXP\Models\PhysicalInterface */;
    $isLAG      = $countPis > 1 ? 1 : 0;
?>

<div class="col-sm-12 mb-4">
    <div class="card">
        <?php // ── Connection Header ── ?>
        <div class="card-header d-flex align-items-center py-2" style="background-color: #f8f9fa;">
            <h5 class="mb-0 mr-auto">
                Connection <?= $t->nbVi ?>
                <small class="text-muted">
                    <?php if( $t->vi->typePeering() && $countPis ): ?>
                        <?= $t->ee( $firstPi->switchPort->switcher->infrastructureModel->name ) ?>
                    <?php elseif( $t->vi->typeFanout() ): ?>
                        Reseller Fanout
                        <?php if( $countPis && $related = $firstPi->relatedInterface() ): ?>
                            for <a href="<?= route( $t->isSuperUser ? "customer@overview" : "customer@detail" , [ 'cust' => $related->virtualInterface->custid ] ) ?>">
                                <?= $t->ee( $related->virtualInterface->customer->abbreviatedName ) ?>
                            </a>
                        <?php else: ?>
                            <em>(unassigned)</em>
                        <?php endif; ?>
                    <?php elseif( $t->vi->typeReseller() ): ?>
                        Reseller Uplink
                    <?php endif; ?>

                    <?php if( $isLAG ): ?>
                        &mdash; <?= $t->vi->bundleName() ?: 'LAG' ?> (LAG)
                    <?php else: ?>
                        <?= $t->insert( 'customer/overview-tabs/ports/pi-status', [ 'pi' => $firstPi, 'vi' => $t->vi, 'isSuperUser' => $t->isSuperUser ] ); ?>
                    <?php endif; ?>
                </small>
            </h5>

            <?php if( $t->isSuperUser ): ?>
                <a class="btn btn-sm btn-white" href="<?= route( 'virtual-interface@edit', [ 'vi' => $t->vi->id ] ) ?>" title="Edit">
                    <i class="fa fa-pencil"></i>
                </a>
            <?php endif; ?>
        </div>

        <div class="card-body pt-3 pb-2">
            <?php // ── Port Details ── ?>
            <?php if( $countPis > 0 ): ?>
                <?php $countPi = 1 ?>
                <?php foreach( $pis as $pi ): ?>
                    <?php if( $isLAG ): ?>
                        <h6 class="mb-1 <?= $countPi > 1 ? 'mt-2' : '' ?>">
                            Port <?= $countPi ?> of <?= $countPis ?> in LAG
                            <?= $t->insert( 'customer/overview-tabs/ports/pi-status', [ 'pi' => $pi, 'isSuperUser' => $t->isSuperUser ] ); ?>
                        </h6>
                    <?php endif; ?>

                    <table class="table table-sm table-borderless table-striped table-connection mb-2">
                        <tr>
                            <td><b>Switch:</b></td>
                            <td><?= $t->ee( $pi->switchPort->switcher->name ) ?></td>
                            <td><b>Switch Port:</b></td>
                            <td><?= $t->ee( $pi->switchPort->name ) ?></td>
                            <td><b>Speed:</b></td>
                            <td>
                                <?= $t->scaleSpeed( $pi->configuredSpeed() ) ?>
                                <?php if( $pi->isRateLimited() ): ?>
                                    <span class="badge badge-info" data-toggle="tooltip" title="Rate Limited">RL</span>
                                <?php endif; ?>
                                <?php if( $pi->duplex !== 'full' ): ?>(HD)<?php endif; ?>
                            </td>
                            <?php if( $pi->switchPort->switcher->mauSupported ): ?>
                                <td><b>Media:</b></td>
                                <td><?= $t->ee( $pi->switchPort->mauType ) ?></td>
                            <?php else: ?>
                                <td><b>Duplex:</b></td>
                                <td><?= $t->ee( $pi->duplex ) ?></td>
                            <?php endif; ?>
                        </tr>
                        <tr>
                            <?php if( $cabinet = $pi->switchPort->switcher->cabinet ): ?>
                                <td><b>Location:</b></td>
                                <td><?= $t->ee( $cabinet->location->name ) ?></td>
                                <td><b>Colo Cabinet ID:</b></td>
                                <td><?= $t->ee( $cabinet->name ) ?></td>
                            <?php else: ?>
                                <td colspan="4"></td>
                            <?php endif; ?>
                            <?php if( $ppp = $pi->switchPort->patchPanelPort ): ?>
                                <td><b>XConnect Port:</b></td>
                                <td class="wrap">
                                    <?= $t->ee( $ppp->patchPanel->colo_reference ) ?> -
                                    <?php if( $t->isSuperUser ): ?>
                                        <a href="<?= route( 'patch-panel-port@list-for-patch-panel' , [ "pp" => $ppp->patch_panel_id ] ) ?>">
                                            <?= $t->ee( $ppp->name() ) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= $t->ee( $ppp->name() ) ?>
                                    <?php endif; ?>
                                </td>
                                <td><b>XConnect Status:</b></td>
                                <td>
                                    <?= $t->ee( $ppp->states() ) ?>
                                    <?php if( $ppp->stateConnected() ): ?>
                                        <?= $ppp->connected_at ?>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <td colspan="4"></td>
                            <?php endif; ?>
                        </tr>
                    </table>
                    <?php $countPi++ ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p>
                    No physical interfaces defined.
                    <?php if( $t->isSuperUser ): ?>
                        <a href="<?= route( "physical-interface@create", [ "vi" => $t->vi->id ] ) ?>">Create one...</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php // ── VLAN Details ── ?>
            <?php if( $vlis->isNotEmpty() ): ?>
                <?php foreach( $vlis as $vli ): ?>
                    <?php $vlanid = $vli->vlanid ?>
                    <?php if( $vli->vlan->private ): ?>
                        <?php if( !isset( $pvlans ) ): ?>
                            <?php $pvlans = $t->c->privateVlanDetails() ?>
                        <?php endif; ?>
                        <h6 class="mt-2">
                            Private VLAN Service
                            <small><?= config( "identity.orgname" ) ?> Reference: #<?= $vli->vlanid ?></small>
                        </h6>
                        <table class="table table-sm table-borderless table-striped mb-2">
                            <tr>
                                <td><b>Name</b></td>
                                <td><?= $t->ee( $vli->vlan->name ) ?></td>
                                <td><b>Tag</b></td>
                                <td><?= $t->ee( $vli->vlan->number ) ?></td>
                                <td><b>Other Members:</b></td>
                                <td>
                                    <?php if( count( $pvlans[ $vli->vlanid ][ 'members'] ) === 1 ): ?>
                                        <em>None - single member</em>
                                    <?php else: ?>
                                        <?php foreach( $pvlans[ $vli->vlanid ][ 'members'] as $m ): ?>
                                            <?= $t->ee( $m->abbreviatedName )?><br />
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    <?php else: ?>
                        <h6 class="mt-2"><?= $t->ee( $vli->vlan->name ) ?>:</h6>
                        <table class="table table-sm table-borderless table-striped mb-2">
                            <?php if( $vli->ipv6enabled && $v6 = $vli->ipv6address ): ?>
                                <tr>
                                    <td><b>IPv6 Address:</b></td>
                                    <td>
                                        <span class="tw-font-mono">
                                            <?= $t->ee( $v6->address ) ?><?php if( isset( $t->netInfo[ $vlanid ][ 6 ][ 'masklen' ] ) ) : ?>/<?= $t->netInfo[ $vlanid ][ 6 ][ "masklen" ] ?><?php endif;?>
                                        </span>
                                    </td>
                                    <td><b>IPv6 RS/RC MD5:</b></td>
                                    <td>
                                        <?php if( $vli->ipv6bgpmd5secret ): ?>
                                            <span class="tw-font-mono"><?= $t->ee( $vli->ipv6bgpmd5secret ) ?></span>
                                        <?php else: ?>
                                            <em>(not configured)</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if( $vli->ipv4enabled && $v4 = $vli->ipv4address ): ?>
                                <tr>
                                    <td><b>IPv4 Address:</b></td>
                                    <td>
                                        <span class="tw-font-mono">
                                            <?= $t->ee( $v4->address ) ?><?php if( isset( $t->netInfo[ $vlanid ][ 4 ][ 'masklen' ] ) ) : ?>/<?= $t->netInfo[ $vlanid ][ 4 ][ "masklen" ] ?><?php endif;?>
                                        </span>
                                    </td>
                                    <td><b>IPv4 RS/RC MD5:</b></td>
                                    <td>
                                        <?php if( $vli->ipv4bgpmd5secret ): ?>
                                            <span class="tw-font-mono"><?= $t->ee( $vli->ipv4bgpmd5secret ) ?></span>
                                        <?php else: ?>
                                            <em>(not configured)</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><b>Route Server Client:</b></td>
                                <td><?= $vli->rsclient ? "Yes" : "No" ?></td>
                                <td><b>MAC Address:</b></td>
                                <td>
                                    <?php foreach( $vli->layer2Addresses as $l2a ): ?>
                                        <span class="tw-font-mono"><?= $l2a->macFormatted( ':' ) ?></span><br />
                                    <?php endforeach; ?>
                                    <?php if( config( 'ixp_fe.layer2-addresses.customer_can_edit' ) ): ?>
                                        <a href="<?= route( "layer2-address@forVlanInterface", [ "vli" => $vli->id ] ) ?>">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><b>Peering VLAN:</b></td>
                                <td><?= $vli->vlantag != 0 ? $vli->vlantag : 'Untagged' ?></td>
                                <?php if( $t->as112UiActive() ): ?>
                                    <td><b>AS112 Client:</b></td>
                                    <td><?= $vli->as112client ? "Yes" : "No" ?></td>
                                <?php else: ?>
                                    <td colspan="2"></td>
                                <?php endif; ?>
                            </tr>
                        </table>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if( $t->vi->typePeering() ): ?>
                    <p class="text-muted mb-0">No VLAN interfaces defined.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php // ── Graphs ── ?>
            <?php if( $countPis > 0 ): ?>
                <div class="row mt-3">
                    <?php if( $isLAG && $t->vi->isGraphable() ): ?>
                        <div class="col-12 mb-3">
                            <div class="card border">
                                <div class="card-header d-flex py-2 bg-white">
                                    <h6 class="mb-0 mr-auto">Aggregate Day Graph for LAG</h6>
                                    <a class="btn btn-white btn-sm py-0" href="<?= route( "statistics@member-drilldown", [ 'type' => 'vi', 'typeid' => $t->vi->id ] ) ?>">
                                        <i class="fa fa-search"></i>
                                    </a>
                                </div>
                                <div class="card-body py-2">
                                    <?= $t->grapher->virtint( $t->vi )->renderer()->boxUplot() ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach( $pis as $pi ): ?>
                        <?php if( !$pi->isGraphable() ) { continue; } ?>
                        <div class="<?= $isLAG ? 'col-lg-6' : 'col-12' ?> mb-3">
                            <div class="card border">
                                <div class="card-header d-flex py-2 bg-white">
                                    <h6 class="mb-0 mr-auto"><?= $t->ee( $pi->switchPort->switcher->name ) ?> / <?= $t->ee( $pi->switchPort->name ) ?></h6>
                                    <a class="btn btn-white btn-sm py-0" href="<?= route( "statistics@member-drilldown", [ 'type' => 'pi', 'typeid' => $pi->id ] ) ?>">
                                        <i class="fa fa-search"></i>
                                    </a>
                                </div>
                                <div class="card-body py-2">
                                    <?= $t->grapher->physint( $pi )->renderer()->boxUplot() ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
