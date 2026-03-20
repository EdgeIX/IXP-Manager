<?php
    $isCustUser = Auth::getUser()->isCustUser();
    $c = $t->c; /** @var $c \IXP\Models\Customer */
?>

<div class="row">
    <div class="col-lg-6 mb-4">
        <h3>
            NOC Details
        </h3>
        <hr>
        <?= Former::open()
            ->populate( $t->dataNocDetail )
            ->method( 'post' )
            ->id( "noc" )
            ->action( route ( "dashboard@store-noc-details" ) )
            ->customInputWidthClass( 'col-sm-6' )
            ->customLabelWidthClass( 'col-sm-3' )
            ->actionButtonsCustomClass( "grey-box")
        ?>

        <?= Former::phone( 'nocphone' )
            ->label( 'Phone' )
            ->placeholder( config( 'ixp_fe.customer.form.placeholders.phone' ) )
            ->blockHelp( 'Working hours phone number for contacting the ' . config( 'ixp_fe.lang.customer.one' ) . ' NOC.<br><br>'
                . 'This is available to all other ' . config( 'ixp_fe.lang.customer.many' ) . '.' );
        ?>

        <?= Former::phone( 'noc24hphone' )
            ->label( '24h Phone' )
            ->placeholder( config( 'ixp_fe.customer.form.placeholders.phone' ) )
            ->blockHelp( '24/7 emergency phone number for contacting the ' . config( 'ixp_fe.lang.customer.one' ) . ' NOC.<br><br>'
                . 'This is available to all other ' . config( 'ixp_fe.lang.customer.many' ) . '.' );
        ?>

        <?= Former::email( 'nocemail' )
            ->label( 'Email' )
            ->placeholder( 'noc@example.com' )
            ->blockHelp( 'The NOC email is used in ' . config( 'ixp_fe.lang.customer.one' ) . ' lists. We try to encourage '
                . 'the use of a role alias such as noc@example.com but this does not '
                . 'always work out.<br><br>'
                . 'This is available to all other ' . config( 'ixp_fe.lang.customer.many' ) . '.' );
        ?>

        <?= Former::select( 'nochours' )
            ->label( 'Hours' )
            ->fromQuery( \IXP\Models\Customer::$NOC_HOURS )
            ->placeholder( 'Choose NOC Hours' )
            ->addClass( 'chzn-select' )
            ->blockHelp( 'The hours during which the NOC is available.' );
        ?>

        <?= Former::url( 'nocwww' )
            ->label( 'Website' )
            ->placeholder( 'http://www.noc.example.com/' )
            ->blockHelp( 'An optional NOC information email page / status page.' );
        ?>

        <?php if( !$isCustUser ): ?>
            <?= Former::actions(
                Former::primary_submit( 'Update NOC Details' )->class( "mb-sm-0 mb-2" ),
                Former::success_button( 'Help' )->id( 'help-btn' )->class( "mb-sm-0 mb-2" )
            );
            ?>
        <?php endif; ?>

        <?= Former::close() ?>
    </div>
    <div class="col-lg-6">
        <?php if( !config('ixp.reseller.no_billing') || !$t->resellerMode() || !$c->resellerObject()->exists() ): ?>
            <h3>
                Billing Details
            </h3>
            <hr>
            <?= Former::open()
                ->populate( $t->dataBillingDetail )
                ->method( 'post' )
                ->id( "billing" )
                ->action( route ( "dashboard@store-billing-details" ) )
                ->customInputWidthClass( 'col-sm-6' )
                ->customLabelWidthClass( 'col-sm-3' )
                ->actionButtonsCustomClass( "grey-box")
            ?>

            <?= Former::text( 'billingContactName' )
                ->label( 'Contact' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingAddress1' )
                ->id( 'billingAddress1' )
                ->label( 'Address' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingAddress2' )
                ->id( 'billingAddress2' )
                ->label( ' ' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingAddress3' )
                ->id( 'billingAddress3' )
                ->label( ' ' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingTownCity' )
                ->id( 'billingTownCity' )
                ->label( 'City' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingPostcode' )
                ->id( 'billingPostcode' )
                ->label( 'Postcode' )
                ->blockHelp( '' );
            ?>

            <?= Former::select( 'billingCountry' )
                ->label( 'Country' )
                ->fromQuery( $t->countries, 'name', 'iso_3166_2' )
                ->placeholder( 'Choose a country' )
                ->addClass( 'chzn-select' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingEmail' )
                ->id( 'billingEmail' )
                ->label( 'Email' )
                ->placeholder( 'billing@example.com' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'billingTelephone' )
                ->id( 'billingTelephone' )
                ->label( 'Telephone' )
                ->placeholder( '+353 1 234 5678' )
                ->blockHelp( '' );
            ?>

            <?= Former::text( 'invoiceEmail' )
                ->id( 'invoiceEmail' )
                ->label( 'Invoice E-Mail' )
                ->placeholder( 'invoicing@example.com' )
                ->blockHelp( '' );
            ?>

            <?php if( !$isCustUser ): ?>
                <?= Former::actions(
                    Former::primary_submit( 'Update Billing Details' ),
                    Former::success_button( 'Help' )->class( "help-btn mb-sm-0 mb-2" )
                );
                ?>
            <?php endif; ?>

            <?= Former::close() ?>
        <?php endif; ?>
    </div>

    <div class="col-lg-6">
        <h3>
            AS-SETS
        </h3>
        <table class="table table-striped">
            <tr>
                <td>
                    Peering Policy
                </td>
                <td>
                    <?= $t->ee( $c->peeringpolicy ) ?>
                </td>
            </tr>
            <tr>
                <td>
                    IRRDB source
                </td>
                <td>
                    <?php if( $c->irrdb ): ?>
                        <?= $t->ee( $c->irrdbConfig->source )?>

                        <?php if( $c->routeServerClient() && $c->irrdbFiltered() ): ?>
                            (<a href="<?= route( "irrdb@list", [ "cust" => $c->id, "type" => 'prefix', "protocol" => $c->isIPvXEnabled( 4) ? 4 : 6 ] ) ?>">entries</a>)
                        <?php endif; ?>

                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>
                    ASN
                </td>
                <td>
                    <?= $t->asNumber( $c->autsys ) ?>
                </td>
            </tr>
            <tr>
                <td>
                    IPv4 AS-SET
                </td>
                <td>
                    <?= $t->ee( $c->peeringmacro ) ?>
                </td>
            </tr>
            <tr>
                <td>
                    IPv6 AS-SET
                </td>
                <td>
                    <?= $t->ee( $c->peeringmacrov6 ) ?>
                </td>
            </tr>
        </table>
    </div>

    <?php if( $c->autsys && $c->in_peeringdb ): ?>
    <div class="col-lg-6">
        <h3>
            PeeringDB
        </h3>
        <table class="table table-striped" id="peeringdb-table">
            <tr>
                <td>ASN</td>
                <td>AS<?= $t->ee( $c->autsys ) ?></td>
            </tr>
            <tr>
                <td>Max Prefixes IPv4</td>
                <td id="pdb-v4"><?= $c->maxprefixes !== null ? $t->ee( $c->maxprefixes ) : '<span class="text-muted">—</span>' ?></td>
            </tr>
            <tr>
                <td>Max Prefixes IPv6</td>
                <td id="pdb-v6"><?= $c->maxprefixesv6 !== null ? $t->ee( $c->maxprefixesv6 ) : '<span class="text-muted">—</span>' ?></td>
            </tr>
        </table>
        <button type="button" class="btn btn-primary btn-sm" id="pdb-sync-btn"
                data-url="<?= route( 'dashboard@peeringdb-sync-prefixes' ) ?>"
                data-token="<?= csrf_token() ?>">
            <i class="fa fa-refresh" id="pdb-sync-icon"></i> Sync from PeeringDB
        </button>
        <span id="pdb-sync-msg" class="ml-2 small"></span>
    </div>
    <?php endif; ?>
</div>

<?php $this->section( 'scripts' ) ?>
<script>
$( '#pdb-sync-btn' ).on( 'click', function() {
    var btn  = $( this );
    var icon = $( '#pdb-sync-icon' );
    var msg  = $( '#pdb-sync-msg' );

    btn.prop( 'disabled', true );
    icon.addClass( 'fa-spin' );
    msg.text( '' ).removeClass( 'text-success text-danger text-warning' );

    $.ajax({
        url:    btn.data( 'url' ),
        method: 'POST',
        data:   { _token: btn.data( 'token' ) },
        success: function( res ) {
            if ( res.error ) {
                msg.addClass( 'text-danger' ).text( res.error );
            } else if ( res.warning ) {
                msg.addClass( 'text-warning' ).text( res.warning );
            } else {
                if ( res.v4 !== null ) $( '#pdb-v4' ).text( res.v4 );
                if ( res.v6 !== null ) $( '#pdb-v6' ).text( res.v6 );

                if ( Object.keys( res.changed ).length > 0 ) {
                    msg.addClass( 'text-success' ).text( 'Updated.' );
                } else {
                    msg.addClass( 'text-success' ).text( 'Already up to date.' );
                }
            }
        },
        error: function( xhr ) {
            var errMsg = 'Sync failed.';
            try { errMsg = xhr.responseJSON.error || errMsg; } catch(e) {}
            msg.addClass( 'text-danger' ).text( errMsg );
        },
        complete: function() {
            btn.prop( 'disabled', false );
            icon.removeClass( 'fa-spin' );
        }
    });
});
</script>
<?php $this->append() ?>
