
function fn_import ( int remote_as )
{
    if !(avoid_martians()) then {
        return false;
    }

    # Belt and braces: must have at least one ASN in the path
    if( bgp_path.len < 1 ) then {
        return false;
    }

    # Belt and braces: no one needs an ASN path with > 64 hops, that's just broken
    if( bgp_path.len > 64 ) then {
        return false;
    }

    <?php if( $t->router->rpki ): ?>

    # RPKI check
    if( roa_check( t_roa, net, bgp_path.last_nonaggregated ) = ROA_INVALID ) then {
        return false;
    } else if( roa_check( t_roa, net, bgp_path.last_nonaggregated ) = ROA_VALID ) then {
        return true;
    }

    <?php else: ?>

    # Skipping RPKI check, protocol not enabled.

    <?php endif; ?>

    return true;
}

<?php foreach( $t->ints as $int ):
    
        // do not set up a session to ourselves!
        if( $int['autsys'] == $t->router->asn ):
            continue;
        endif;

?>

protocol bgp pb_as<?= $int['autsys'] ?>_vli<?= $int['vliid'] ?>_ipv<?= $int['protocol'] ?? 4 ?> {
        description "AS<?= $int['autsys'] ?> - <?= $int['cname'] ?>";
        local as routerasn;
        source address routeraddress;
        neighbor <?= $int['address'] ?> as <?= $int['autsys'] ?>;
        ipv<?= $int['protocol'] ?? 4 ?> {
            import where fn_import( <?= $int['autsys'] ?> );
            export where proto = "static_as112";
        };
        <?php if( $int['bgpmd5secret'] && !$t->router->skip_md5 ): ?>password "<?= $int['bgpmd5secret'] ?>";<?php endif; ?>

}

<?php endforeach; ?>
