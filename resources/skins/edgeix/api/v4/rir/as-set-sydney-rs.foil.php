password: <?= config('ixp_api.rir.password') ?>


as-set:         AS-SET-EDGEIX-SYD-RS
descr:          ASNs connected to the Route Servers at EdgeIX Sydney
descr:          EdgeIX operates multiple IXPs across Australia
admin-c:        TCMJ1-AP
tech-c:         TCMJ1-AP
notify:         notify@edgeix.net
remarks:        EdgeIX peer ASNs are listed in AS-SET-EDGEIX-CONNECTED
mnt-by:         MAINT-TCMJ-AU
<?php foreach( $t->customers as $c ): ?>
<?php if( $c->isRouteServerClient( ) && $c->hasVlan(200) ): ?>
members:        <?= $c->resolveAsMacro( 4, 'AS' ) ?>

<?php endif; ?>
<?php endforeach; ?>
source:         APNIC

