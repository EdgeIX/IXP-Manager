<?php use IXP\Models\PatchPanelPort;

$this->layout( 'layouts/ixpv4' );
    $ppp = $t->ppp; /** @var $ppp PatchPanelPort */
    $pppname = $ppp->name();

    /**
     * EDGEIX SKIN OVERRIDE of resources/views/patch-panel-port/edit.foil.php
     *
     * Adds a "Remote switch" checkbox so staff can link a panel port to a
     * switch at ANOTHER site — long-lined / EdgeIX-owned dark-fibre sites
     * (e.g. Vocus PER01 panel → pe1per2 at NEXTDC P2). Upstream filters the
     * Switch dropdown to the panel's own site; that filter is UI-only (the
     * update() action accepts any switch port), so this override only widens
     * the dropdown when the box is ticked. The per-switch Switch Port AJAX
     * has no site filter and works unchanged. See docs/ordering.md.
     *
     * Divergence from upstream: this header, the $eix* block below, the
     * checkbox + dropdown source in the switch card, and the EdgeIX script
     * appended to the scripts section. Everything else is verbatim — re-diff
     * against upstream on merges.
     */
    $eixLocalSwitches = $t->switches;   // keyed by id; panel-site switches only
    $eixAllSwitches   = \IXP\Models\Switcher::selectRaw( 'switch.id, switch.name, l.name AS locname' )
        ->leftJoin( 'cabinet AS cab', 'cab.id', 'switch.cabinetid' )
        ->leftJoin( 'location AS l', 'l.id', 'cab.locationid' )
        ->where( 'switch.active', true )
        ->orderBy( 'l.name' )->orderBy( 'switch.name' )->get();

    // Port already linked cross-site? The local list can't contain the
    // linked switch, so inject it — the form then DISPLAYS the existing
    // link instead of showing an empty dropdown and dropping it on resave.
    $eixRemoteCurrent = false;
    if( $ppp->switchPort && !$eixLocalSwitches->has( $ppp->switchPort->switchid ) ) {
        $eixRemoteCurrent = true;
        $eixLocalSwitches = $eixLocalSwitches->concat( [ $ppp->switchPort->switcher ] )->keyBy( 'id' );
    }
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Patch Panel Port
    /
    Edit&nbsp;&nbsp;[<?= $t->ee( $ppp->patchPanel->name ) ?> - <?= $t->ee( $pppname ) ?>]
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a class="btn btn-white" href="<?= route('patch-panel-port@list-for-patch-panel' ,  [ 'pp' => $ppp->patch_panel_id ]  ) ?>" title="list">
            <span class="fa fa-th-list"></span>
        </a>
    </div>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <?php if ( !$t->allocating && !$t->prewired ): ?>
        <div class="alert alert-warning" role="alert">
            <div class="d-flex align-items-center">
                <div class="text-center">
                    <i class="fa fa-exclamation-circle fa-2x"></i>
                </div>
                <div class="col-sm-12">
                    <b>Note:</b>
                    IXP Manager provides context-aware actions for allocating / setting connected / requested ceases / ceasing a patch
                    panel port and these <i>do the right thing</i>. As such, editing a patch panel port manually through this
                    interface is discouraged for lifecycle changes.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?= $t->alerts() ?>

    <?= Former::open()->method( 'PUT' )
        ->action( route( 'patch-panel-port@update', [ 'ppp' => $ppp->id ] ) )
        ->customInputWidthClass( 'col-lg-4 col-md-4 col-sm-6' )
        ->customLabelWidthClass( 'col-lg-3 col-md-4 col-sm-4' )
        ->actionButtonsCustomClass( "grey-box")
    ?>

    <?php if ( !$t->allocating && !$t->prewired ): ?>
        <?= Former::text( 'number' )
            ->label( 'Patch Panel Port Name' )
            ->forceValue( $t->ee( $pppname ) );
        ?>

        <?= Former::text( 'patch_panel' )
            ->label( 'Patch Panel' );
        ?>

        <?= Former::text( 'cabinet_name' )
            ->label( 'Rack' )
            ->disabled( 'disable' );
        ?>

        <?= Former::text( 'colocation_centre' )
            ->label( 'Colocation Centre' )
            ->disabled( true );
        ?>
    <?php endif; ?>

    <?= Former::text( 'description' )
            ->label( 'Description' )
            ->blockHelp( 'A one line short description to be shown in the list of patch panel ports. '
                . 'Just enough to help explain the port\'s purpose. Detailed information should be '
                . 'placed in the notes below. Can also be used to explain a reserved / broken / '
                . 'other port. Note that this is parsed as Markdown.<br><br>'
                . '<b>NB: A description is discouraged for ' . config( 'ixp_fe.lang.customer.one' ) . ' ports connected to a switch. '
                . 'The ' . config( 'ixp_fe.lang.customer.one' ) . ' name and switch port <em>are the description</em></b>.' );
    ?>

    <?php if (!$t->prewired): ?>
        <?= Former::text( 'colo_circuit_ref' )
            ->label( 'Colocation Circuit Reference' )
            ->blockHelp( 'The cross connect reference as provided by the colocation provider.' );
        ?>

        <?= Former::text( 'colo_billing_ref' )
            ->label( 'Colocation Billing Reference' )
            ->blockHelp( 'The cross connect billing reference as provided by the colocation provider.' );
        ?>

        <?= Former::text( 'ticket_ref' )
            ->label( 'Ticket Reference(s)' )
            ->blockHelp( 'This is a free text field to allow you to add helpdesk ticket reference(s) associated with your member for this connection.' );
        ?>
    <?php endif; ?>

    <?= Former::checkbox( 'duplex' )
        ->label( 'Duplex connection?' )
        ->value( 1 )
        ->inline()
        ->blockHelp('Typically fibre connections are <em>duplex connections</em> in that they use two ports. If this is the '
            . 'case, check this and select the partner port. <em>Duplex ports should generally start with an odd number and '
            . 'have an even numbered partner port (assuming port numbering starts from 1).</em>' );
    ?>

    <span id='duplex-port-area' class='collapse'>
        <?= Former::select( 'partner_port' )
            ->label( 'Partner Port' )
            ->fromQuery( $t->partnerPorts, fn ( $model ) => $t->ee( $model->name ) )
            ->placeholder( 'Choose a partner port' )
            ->addClass( 'chzn-select' )
            ->blockHelp( 'The second half of the duplex port.' );
        ?>
    </span>

    <div class="form-text text-muted former-help-text">
        <div class="card bg-light mb-4">
            <div class="card-body ">
                You have a number of options when assigning a port:
                <ul>
                    <li>
                        If you have pre-wired the patch panel to a port, enter the switch and port here. So long as no <?= config( 'ixp_fe.lang.customer.one' ) ?> has been
                        assigned to the switch port, the patch panel port will remain available but will be marked as connected to
                        the given switch port in the patch panel port list.
                    </li>
                    <li>
                        If the switch port has been allocated to a <?= config( 'ixp_fe.lang.customer.one' ) ?>, then this patch panel port will also be allocated to that <?= config( 'ixp_fe.lang.customer.one' ) ?>.
                        The backend logic will detect if this is the case and update the <?= config( 'ixp_fe.lang.customer.one' ) ?> field. Conversely, if you choose a <?= config( 'ixp_fe.lang.customer.one' ) ?>
                        first, the switch / switch port dropdowns will be populated with only that <?= config( 'ixp_fe.lang.customer.owner' ) ?> assigned ports.
                    </li>
                    <li>
                        Sometimes you will get cross connects that are not intended to be connected to peering switches (e.g. connections to
                        co-located <?= config( 'ixp_fe.lang.customer.one' ) ?> equipment, IXP metro connections, etc.). In these cases, just select the <?= config( 'ixp_fe.lang.customer.one' ) ?> (and if it's the IXP
                        itself, select the IXP <?= config( 'ixp_fe.lang.customer.one' ) ?>) and leave switch / switch port unselected.
                    </li>
                </ul>

                If you need to reset these fields, just click either of the <em>Reset</em> button.
            </div>
        </div>
    </div>

    <div class="card bg-light mb-4">
        <div class="card-body d-flex ">
            <div class="mr-auto col-lg-11 col-md-10">
                <?= Former::checkbox( 'eix_remote_switch' )
                    ->label( 'Remote switch?' )
                    ->value( 1 )
                    ->inline()
                    ->blockHelp( '<b>EdgeIX:</b> tick to list switches at <b>other sites</b> — only for panels long-lined '
                        . 'over EdgeIX-owned fibre to a switch elsewhere (e.g. Vocus PER01 ports terminate on pe1per2 at '
                        . 'NEXTDC P2). The LOA and port stock still correctly use <em>this panel\'s</em> site. Leave '
                        . 'unticked for normal same-site patching.' );
                ?>

                <?= Former::select( 'switch' )
                    ->label( 'Switch' )
                    ->fromQuery( $eixLocalSwitches, fn ( $model ) => $t->ee( $model->name ) )
                    ->placeholder( 'Choose a switch' )
                    ->addClass( 'chzn-select' )
                    ->append(  ( $ppp->switchPort ? "<span class='badge badge-info'> Was " . $t->ee( $ppp->switchPort->switcher->name ) . " </span>"  : '' )
                        . ( $eixRemoteCurrent ? " <span class='badge badge-warning'>remote site</span>" : '' ) );
                ?>

                <?= Former::select( 'switch_port_id' )
                    ->label( 'Switch Port' )
                    ->fromQuery( $t->switchPorts, fn ( $model ) => $t->ee( $model->name ) )
                    ->placeholder( 'Choose a switch port' )
                    ->addClass( 'chzn-select' )
                    ->append(  $ppp->switchPort ? "<span class='badge badge-info'> Was " . $t->ee( $ppp->switchPort->name ) . " </span>" : '' );
                ?>
            </div>

            <div class="my-auto">
                <button id="reset-switch" class="btn btn-white d-flex">
                    <i class="my-auto fa fa-retweet"></i>
                    &nbsp;Reset
                </button>
            </div>
        </div>
    </div>

    <?php if ( !$t->prewired ): ?>
        <div class="card bg-light mb-4">
            <div class="card-body d-flex">
                <div class="mr-auto col-lg-11 col-md-10">
                    <?= Former::select( 'customer_id' )
                        ->label( ucfirst( config( 'ixp_fe.lang.customer.one' ) ) )
                        ->fromQuery( $t->customers , fn ( $model ) => $t->ee( $model->name ) )
                        ->placeholder( 'Choose a ' . config( 'ixp_fe.lang.customer.one' ) )
                        ->addClass( 'chzn-select' )
                        ->append(  $ppp->customer ? "<span class='badge badge-info'> Was " . $t->ee( $ppp->customer->name ). " </span>" : '' );
                    ?>
                </div>

                <div class="my-auto">
                    <button id="resetCustomer" class="btn btn-white d-flex">
                        <i class="my-auto fa fa-retweet"></i>
                        &nbsp;Reset
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?= Former::select( 'state' )
        ->label( 'Patch Panel Port Status' )
        ->options( $t->states )
        ->placeholder( 'Choose a states' )
        ->addClass( 'chzn-select' )
        ->blockHelp( 'The state of the patch panel port.' )
        ->disabled( $t->prewired ? true : false );
    ?>

    <?php if( $t->allocating ): ?>
        <span id='pi_status_area' class='collapse'>
            <?= Former::select( 'pi_status' )
                ->label( 'Physical Interface Status' )
                ->options( \IXP\Models\PhysicalInterface::$STATES )
                ->placeholder( 'Choose a status' )
                ->addClass( 'chzn-select' )
                ->blockHelp( 'This allows you to update the physical interface status when updating the patch panel port status. '
                    . '<b>The current state is shown by default.</b>' );
            ?>
        </span>
    <?php endif; ?>

    <?php if( !$t->prewired ): ?>
        <div class="form-group row">
            <div class="col-sm-8">
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-tabs card-header-tabs">
                            <li role="presentation" class="nav-item">
                                <a class="tab-link-body-note nav-link active" href="#body1">Public Notes</a>
                            </li>
                            <li role="presentation" class="nav-item">
                                <a class="tab-link-preview-note nav-link " href="#preview1">Preview</a>
                            </li>
                        </ul>
                    </div>
                    <div class="tab-content card-body">
                        <div role="tabpanel" class="tab-pane show active" id="body1">
                            <?= Former::textarea( 'notes' )
                                ->id( 'notes' )
                                ->class( 'notes' )
                                ->label( '' )
                                ->rows( 20 )
                                ->blockHelp( "These notes are visible (but not editable) to the member. You can use markdown here." )
                            ?>
                        </div>
                        <div role="tabpanel" class="tab-pane" id="preview1">
                            <div class="bg-light p-4 well-preview">
                                Loading...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-group row mt-4 mb-4">
        <div class="col-sm-8">
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs">
                        <li role="presentation" class="nav-item">
                            <a class="tab-link-body-note nav-link active" href="#body2">Private Notes</a>
                        </li>
                        <li role="presentation" class="nav-item">
                            <a class="tab-link-preview-note nav-link" href="#preview2">Preview</a>
                        </li>
                    </ul>
                </div>

                <div class="tab-content card-body">
                    <div role="tabpanel" class="tab-pane show active" id="body2">
                        <?= Former::textarea( 'private_notes' )
                            ->id( 'private_notes' )
                            ->label( '' )
                            ->class( 'notes' )
                            ->rows( 20 )
                            ->blockHelp( "These notes are <b>NOT</b> visible to the member. You can use markdown here." )
                        ?>
                    </div>
                    <div role="tabpanel" class="tab-pane" id="preview2">
                        <div class="bg-light p-4 well-preview" style="background: rgb(255,255,255);">
                            Loading...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ( !$t->prewired ): ?>
        <?php if( !$t->allocating ): ?>
            <?= Former::date( 'assigned_at' )
                ->label( 'Assigned At' )
                ->append( '<button class="btn-white btn btn-today" data-parent-id="assigned_at" type="button">Today</button>' )
                ->blockHelp( 'help text' )
                ->value( date( 'Y-m-d' ) );
            ?>

            <?= Former::date( 'connected_at' )
                ->label( 'Connected At' )
                ->append( '<button class="btn-white btn btn-today" data-parent-id="connected_at" type="button">Today</button>' )
                ->blockHelp( 'help text' );
            ?>

            <?= Former::date( 'cease_requested_at' )
                ->label( 'Ceased Requested At' )
                ->append( '<button class="btn-white btn btn-today" data-parent-id="cease_requested_at" type="button">Today</button>' )
                ->blockHelp( 'help text' );
            ?>

            <?= Former::date( 'ceased_at' )
                ->label( 'Ceased At' )
                ->append( '<button class="btn-white btn btn-today" data-parent-id="ceased_at" type="button"">Today</button>' )
                ->blockHelp( 'help text' );
            ?>

            <?= Former::text( 'last_state_change' )
                ->label( 'Last State change At' )
                ->blockHelp( 'help text' )
                ->disabled( true );
            ?>
        <?php endif; ?>

        <?= Former::select( 'chargeable' )
            ->label( 'Chargeable' )
            ->options( PatchPanelPort::$CHARGEABLES )
            ->addClass( 'chzn-select' )
            ->blockHelp( 'Usually IXPs request their members to <em>come to them</em> and bear the costs of that. '
                . 'However, sometimes a co-location facility may charge the IXP for a half circuit or the IXP may need '
                . 'order and pay for the connection. This can be used, for example, to reconcile billing.' );
        ?>

        <?= Former::radios( 'Internal Use' )
            ->radios([
                'Yes' => ['name' => 'internal_use', 'value' => '1' ],
                'No'  => ['name' => 'internal_use', 'value' => '0' ],
            ])->inline()->check( $ppp->internal_use ? '1' : '0' )
            ->blockHelp( 'Indicates that this cross connect is for IXP use rather than relating to a member.' );
        ?>

        <?= Former::select( 'owned_by' )
            ->label( 'Owned By' )
            ->options( PatchPanelPort::$OWNED_BY )
            ->addClass( 'chzn-select' )
            ->blockHelp( 'Indicates who ordered the cross connect / who the contracting entity is.' );
        ?>
    <?php endif; ?>

    <?= Former::hidden( 'patch_panel_port_id' )
        ->value( $ppp->id )
    ?>

    <?= Former::hidden( 'allocated' )
        ->id( 'allocated' )
        ->value( $t->allocating )
    ?>

    <?= Former::hidden( 'prewired' )
        ->id( 'prewired' )
        ->value( $t->prewired )
    ?>

    <?= Former::hidden( 'patch_panel_id' )
        ->id( 'patch_panel_id' )
        ->value( $ppp->patch_panel_id )
    ?>

    <?php if ( $t->prewired ): ?>
        <?= Former::hidden( 'state' )
            ->id( 'state' )
            ->forceValue( PatchPanelPort::STATE_PREWIRED )
        ?>
    <?php endif; ?>

    <?=Former::actions( Former::primary_submit( 'Save Changes' )->class( "mb-2 mb-sm-0" ),
        Former::secondary_link( 'Cancel' )->href( route ( 'patch-panel-port@list-for-patch-panel' , [ 'pp' => $ppp->patch_panel_id ] ) )->class( "mb-2 mb-sm-0" ),
        Former::success_button( 'Help' )->id( 'help-btn' )->class( "mb-2 mb-sm-0" )
    )?>

    <?= Former::close() ?>

<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <?= $t->insert( 'patch-panel-port/js/edit' ); ?>
    <script>
        // EdgeIX: "Remote switch" toggle — swaps the Switch dropdown between
        // the panel-site list and the full active fleet. Selecting a switch
        // still fires the upstream per-switch AJAX for the Switch Port list
        // (no site filter server-side), so remote ports load and save with
        // zero server changes. dd_switch / dd_switch_port / btn_reset_switch /
        // div_status_area are the upstream globals from js/edit above.
        ( function() {
            const eixLocal = <?= json_encode( $eixLocalSwitches->map( fn( $s ) => [ 'id' => $s->id, 'name' => $s->name ] )->values() ) ?>;
            const eixAll   = <?= json_encode( $eixAllSwitches->map( fn( $s ) => [ 'id' => $s->id, 'name' => $s->name, 'loc' => $s->locname ] )->values() ) ?>;
            const eixCb    = $( '#eix_remote_switch' );

            function eixRebuildSwitchList() {
                const remote  = eixCb.is( ':checked' );
                const current = dd_switch.val();
                let   keep    = false;
                let   options = `<option value=''>Choose a switch</option>`;

                ( remote ? eixAll : eixLocal ).forEach( s => {
                    const label = remote && s.loc ? `${s.name} — ${s.loc}` : s.name;
                    options += `<option value='${s.id}'>${htmlEntities( label )}</option>`;
                    if( String( s.id ) === current ) {
                        keep = true;
                    }
                } );

                dd_switch.html( options );

                if( keep ) {
                    // same switch still selected → leave the port list alone
                    dd_switch.val( current );
                } else if( current !== '' && current !== null ) {
                    // selection vanished (box unticked with a remote switch chosen)
                    dd_switch_port.html( `<option value=''>Choose a switch port</option>` ).trigger( 'change.select2' );
                }

                dd_switch.trigger( 'change.select2' );
            }

            eixCb.on( 'change', eixRebuildSwitchList );

            // Upstream's Reset rebuilds from the local list only — rebind so
            // Reset respects the toggle.
            btn_reset_switch.off( 'click' ).on( 'click', function( e ) {
                e.preventDefault();
                dd_switch.val( '' );
                eixRebuildSwitchList();
                dd_switch_port.html( `<option value=''>Choose a switch port</option>` ).trigger( 'change.select2' );
                div_status_area.hide();
            } );

            <?php if( $eixRemoteCurrent ): ?>
                // Port already linked cross-site → open with the box ticked
                // and the full list shown (linked switch stays selected).
                eixCb.prop( 'checked', true );
                eixRebuildSwitchList();
            <?php endif; ?>
        } )();
    </script>
<?php $this->append() ?>
