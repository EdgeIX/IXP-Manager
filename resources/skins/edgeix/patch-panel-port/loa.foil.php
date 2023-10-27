<?php

use IXP\Models\Router;

$ppp = $t->ppp; /** @var $ppp \IXP\Models\PatchPanelPort*/
?>
<html>
<head>
    <title>
        LoA - <?= $ppp->circuitReference() ?> - <?= now()->format('Y-m-d' ) ?>
    </title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>

</head>
<body>

<table width="100%" border="0">
    <tr>
        <td style="text-align: left; vertical-align: top; width="50%">
                <img alt="[EdgeIX Logo]" src="data:image/png;base64,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" />

        </td>
    </tr>
</table>

<table  width="100%" border="0">
<td style="text-align: right; width="50%">
            <?= date( "F d, Y" ) ?>
        </td>
</table>

<h2>
    Letter of Authority (LoA) - Our Reference:</b> <?= $ppp->circuitReference() ?>
</h2>

<hr>

<p>
    EdgeIX hereby authorises <?= $ppp->customer->name ?>
    and / or its agents to order a connection to the following demarcation point:
</p>

<p>
    <table border="0" width="100%" style="border: 2px solid #000000; padding: 5px;">
        <tr>
            <td width="10%"></td>
            <td><b>Facility:</b></td>
            <td><?= $t->ee( $ppp->patchPanel->cabinet->location->name ) ?></td>
        </tr>
        <tr>
            <td></td>
            <td><b>Demarc:</b></td>
            <td><?= $t->ee( $ppp->patchPanel->colo_reference ) ?></td>
        </tr>
        <tr>
            <td></td>
            <td><b>Port:</b></td>
            <td><?= $t->ee( $ppp->name() ) ?></td>
        </tr>
        <tr>
            <td></td>
            <td><b>Type:</b></td>
            <td><?= $t->ee( $ppp->patchPanel->cableType() ) ?> / <?= $t->ee( $ppp->patchPanel->connectorType() ) ?></td>
        </tr>
        <tr>
            <td></td>
            <td><b>Media:</b></td>
            <td>
                <?= $t->ee( $ppp->switchPort->mauType ) ?>
            </td>
        </tr>
        <tr>
            <td></td>
            <td><b>Provider:</b></td>
            <td>
                <?php if ($ppp->patchPanel->cabinet->type): ?>
                    <?= $t->ee( $ppp->patchPanel->cabinet->type ) ?> (Cage Provider)
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td></td>
            <td><b>Peering Details:</b></td>
            <td>
                <strong>AS<?= $ppp->customer->autsys ?><br/></strong>
                <?php
                $vi = null;
                 foreach ($ppp->customer->virtualInterfaces as $cvi) {
                    if ($ppp->switchPort->id == $cvi->switchPort()->id) {
                        $vi = $cvi;
                        break;
                    }
                }
                $ips = [];
                if ($vi && $vlis = $vi->vlanInterfaces) {
                    $netmask = [];
                    foreach( $vlis as $vli ) {
                        if ($vli->vlan->private) {
                            continue;
                        }
                        foreach ($vli->vlan->networksInfo as $ni) {
                            $netmask[$ni->protocol] = $ni->masklen;
                        }

                        if( $vli->ipv4enabled && $vli->ipv4address ) {
                            $ips[] = ['type' => 'IPv4', 'addr' => $vli->ipv4address->address, 'mask' => $netmask[4] ?? '?'];
                        }
                        if( $vli->ipv6enabled && $vli->ipv6address ) {
                            $ips[] = ['type' => 'IPv6', 'addr' => $vli->ipv6address->address, 'mask' => $netmask[6] ?? '?'];
                        }
                    }
                }
                ?>
                <?php foreach( $ips as $ip ): ?>
                    <?= $t->ee( "{$ip['type']}: {$ip['addr']}/{$ip['mask']}" ) ?><br/>
                <?php endforeach; ?>
                <br/>

                <?php
                $routers = [];
                if ($vi && $vlis = $vi->vlanInterfaces) {
                    foreach( $vlis as $vli ) {
                        if ($vli->vlan->private) {
                            continue;
                        }
                        foreach ($vli->vlan->routers as $router) {
                            if (!$router->type == Router::TYPE_ROUTE_SERVER) {
                                continue;
                            }
                            $routers[$router->asn][$router->router_id] ??= [];

                            $routers[$router->asn][$router->router_id][] = $router->peering_ip;
                        }
                    }
                }
                $routeServerNum = 0;
                ?>
                <?php foreach( $routers as $asn => $asnRouters ): ?>
                <strong>AS<?= $t->ee( $asn ) ?></strong><br/>
                    <?php foreach( $asnRouters as $asnRouter ): ?>
                        Route Server <?= ++$routeServerNum ?>: <?= implode(' / ', $asnRouter) ?><br/>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            </td>
        </tr>
    </table>
    <br>

</p>

<p>
    Should you have any questions or concerns regarding this Letter of Authority, please contact our NOC
    via the details found at <a href="https://www.edgeix.net">https://www.edgeix.net</a>.
    <em>We generate our LoA's via our provisioning system. Each LoA can be individually
    authenticated by clicking on the following unique link:</em><br><br>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    &nbsp;&nbsp;&nbsp;&nbsp;<a target="_blank" href="<?= route ( 'patch-panel-port-loa@verify' , [ 'ppp' => $ppp->id , 'code' => $ppp->loa_code ] ) ?>">
       <?= route ( 'patch-panel-port-loa@verify' , [ 'ppp' => $ppp->id , 'code' => $ppp->loa_code ] ) ?>
</p>


<p style="text-align: center; font-size: 0.8em">
    <br><br>
    <em>
        EdgeIX Pty Ltd is an Australian Registered Company.
        More details at: <a href="https://www.edgeix.net/">www.edgeix.net</a>.
    </em>
</p>
</body>
</html>
