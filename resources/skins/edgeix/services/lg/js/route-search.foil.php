<script>
    const dd_source   = $( '#source' );
    const btn_submit  = $( '#submit' );

    <?php
        // Safely extract symbols — handle different data structures from birdseye vs birdwatcher
        $symbols = $t->content->symbols ?? $t->content ?? null;
        $tables = [];
        $protocols = [];
        if( $symbols ) {
            if( isset( $symbols->{'routing table'} ) ) {
                $tables = $symbols->{'routing table'};
            } elseif( isset( $symbols->{'Routing table'} ) ) {
                $tables = $symbols->{'Routing table'};
            }
            if( isset( $symbols->protocol ) ) {
                $protocols = $symbols->protocol;
            } elseif( isset( $symbols->Protocol ) ) {
                $protocols = $symbols->Protocol;
            }
        }
    ?>
    // DEBUG: symbols keys = <?= json_encode( $symbols ? array_keys( (array)$symbols ) : 'null' ) ?>

    let tables    = <?= json_encode( $tables ) ?>.sort();
    let protocols = <?= json_encode( $protocols ) ?>.sort();
    let source    = 'table';

    btn_submit.on( 'click', function( e ) {
        e.preventDefault();
        let net     = $( "#net" ).val().trim();
        let masklen = 32;
        if( net === "" ) {
            return;
        }
        btn_submit.prop('disabled', true);

        if( net.indexOf('/') !== -1 ) {
            masklen = net.substring( net.indexOf('/') + 1);
            net     = net.substring( 0, net.indexOf('/') );
        } else if( net.indexOf(':') !== -1 ) {
            masklen = 128;
        }

        $.get('<?= url('lg/' . $t->lg->router()->handle  . '/route') ?>/' + encodeURIComponent( net ) + '/' +
            encodeURIComponent( masklen ) + '/' +
            source + '/' + encodeURIComponent( dd_source.val() ), function( html ) {
                $( '#route-modal .modal-content' ).html( html );
                $( '#route-modal' ).modal( 'show', { backdrop: 'static' } );
            });

            btn_submit.prop('disabled', false);
        });

    $( 'input:radio[name="source_selector"]' ).change( function(){
        if( $( this ).is( ':checked' ) ) {
            dd_source.html( '' );
            if( $(this).val() === "table" ) {
                source = 'table'
                datas = tables;
            } else {
                source = 'protocol'
                datas = protocols;
            }

            datas.forEach( function( e ){
                $( "#source" ).append( `<option value="${e}">${e}</option>` );
            });

            if( $(this).val() === "table" ) {
                $( "#source" ).val('master<?= $t->lg->router()->protocol() ?>');
            }
        }
    });

    $(document).ready(function() {
        tables.forEach( function(e){
            dd_source.append( `<option value="${e}">${e}</option>` );
        });
        dd_source.val( 'master<?= $t->lg->router()->protocol() ?>' );

        // Community search: preset populates custom field
        $('#community-preset').on('change', function() {
            let val = $(this).val();
            if( val ) {
                $('#community-custom').val( val );
            }
        });

        // Community search button
        $('#community-search').on('click', function() {
            let community = $('#community-custom').val().trim();
            if( !community ) {
                community = $('#community-preset').val();
            }
            if( !community ) {
                return;
            }

            // Validate format: x:y or x:y:z
            let parts = community.split(':');
            if( parts.length < 2 || parts.length > 3 ) {
                alert('Invalid community format. Use x:y for standard or x:y:z for large communities.');
                return;
            }

            $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Searching...');

            window.location.href = '<?= url('lg/' . $t->lg->router()->handle . '/routes/community') ?>/' + encodeURIComponent( community );
        });
    });
</script>
