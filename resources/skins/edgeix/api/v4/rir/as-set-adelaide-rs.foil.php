password: <?= config('ixp_api.rir.password') ?>

as-set:         AS24224:AS-EDGEIX-ADL-RS
descr:          ASNs connected to the Route Servers at EdgeIX Adelaide
descr:          EdgeIX operates multiple IXPs across Australia
admin-c:        EPLA20-AP
tech-c:         EPLA20-AP
notify:         notify@edgeix.net
remarks:        EdgeIX peer AS-LIST Adelaide
mnt-by:         MAINT-EDGEIXPTYLTD-AU
<?php foreach( $t->customers as $c ): ?>
<?php if( $c->routeServerClient( ) && $c->hasVLAN(500) ): ?>
members:        <?= $c->asMacro( 4, 'AS' ) ?>

<?php endif; ?>
<?php endforeach; ?>
source:         APNIC
