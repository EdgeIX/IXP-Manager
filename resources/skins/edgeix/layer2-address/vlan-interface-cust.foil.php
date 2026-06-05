<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );

    // ---------------------------------------------------------------------
    // MAC sync state for this VLI (from the ixpm-mac-sync package).
    // Queried directly here so the skin override stays self-contained — the
    // IXP-Manager core controller doesn't know about mac-sync.
    // ---------------------------------------------------------------------
    $macSyncState = \EdgeIX\IxpmMacSync\Models\MacSyncState::where(
        'vlan_interface_id', $t->vli->id
    )->first();

    $syncedMacs  = $macSyncState?->synced_macs ?? [];
    $currentMacs = $t->vli->layer2Addresses
        ->map( fn( $l2 ) => strtolower( $l2->macFormatted( ':' ) ) )
        ->filter()->values()->all();

    $syncInSync  = empty( array_diff( $currentMacs, $syncedMacs ) )
                && empty( array_diff( $syncedMacs, $currentMacs ) );
    $syncPending = (bool) ( $macSyncState?->sync_pending ?? false );
    $syncFailed  = (bool) $macSyncState?->last_sync_failed_at && !$syncPending && !$syncInSync;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    MAC Address Management
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <?php if( $syncInSync ): ?>
        <span class="badge badge-success mr-2" id="mac-sync-badge">
            <i class="fa fa-check"></i> In Sync
        </span>
    <?php elseif( $syncPending ): ?>
        <span class="badge badge-info mr-2" id="mac-sync-badge">
            <i class="fa fa-clock-o"></i> Sync Queued
        </span>
    <?php elseif( $syncFailed ): ?>
        <span class="badge badge-secondary mr-2" id="mac-sync-badge">
            <i class="fa fa-exclamation-circle"></i> Sync Incomplete
        </span>
    <?php else: ?>
        <span class="badge badge-warning mr-2" id="mac-sync-badge">
            <i class="fa fa-exclamation-triangle"></i> Changes Pending
        </span>
    <?php endif; ?>

    <button class="btn btn-primary btn-sm <?= ( $syncInSync || $syncPending ) ? 'disabled' : '' ?>"
            id="btn-mac-sync"
            data-vli-id="<?= $t->vli->id ?>"
            data-token="<?= csrf_token() ?>"
            <?= ( $syncInSync || $syncPending ) ? 'disabled' : '' ?>>
        <i class="fa fa-upload"></i> Sync to Switch
    </button>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-sm-12">
            <?= $t->alerts() ?>

            <?php if( config( 'ixp_fe.layer2-addresses.customer_can_edit') ): ?>
                <?= $t->insert( 'layer2-address/customer-edit-msg.foil.php' ) ?>
            <?php endif; ?>

            <?php if( $syncFailed ): ?>
                <div class="alert alert-warning" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="text-center">
                            <i class="fa fa-exclamation-circle fa-2x"></i>
                        </div>
                        <div class="col-sm-12">
                            <b>Last sync could not be completed</b><br>
                            EdgeIX staff have been notified and will follow up. Your MAC
                            address changes are saved and will be applied once resolved.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if( $t->vli->layer2Addresses()->count() >= config( 'ixp_fe.layer2-addresses.customer_params.max_addresses' ) ): ?>
               <div class="alert alert-warning" role="alert">
                <div class="d-flex align-items-center">
                 <div class="text-center">
                 <i class="fa fa-exclamation-circle fa-2x "></i>
                 </div>
                <div class="col-sm-12">
                 You have reached the maximum number of allowed MAC addresses for this port.
                 Delete a MAC address to enable adding new MAC addresses.
                </div>
               </div>
             </div>
            <?php endif; ?>

            <div id="list-area" class="collapse">
                <table id='layer-2-interface-list' class="table table-striped w-100" data-searching="false" data-paging="false" data-ordering="false" data-info="false">
                    <thead class="thead-dark">
                        <tr>
                            <th style="vertical-align: middle;">
                                MAC Address
                            </th>
                            <th style="vertical-align: middle;">
                                Created At
                            </th>
                            <th style="vertical-align: middle;">
                                Switch
                            </th>
                            <th style="vertical-align: middle;">
                                Action
                                <?php if( $t->vli->layer2Addresses()->count() < config( 'ixp_fe.layer2-addresses.customer_params.max_addresses' ) ): ?>
                                    &nbsp;<div class="btn-group btn-group-sm" id="add-btn" role="group">
                                     <a class="btn btn-white" id="add-l2a" title="Add a new MAC address">
                                    <span class="fa fa-plus"></span>
                                   </a>
                                  </div>
                                <?php else: ?>
                                    &nbsp;<div class="btn-group btn-group-sm" id="add-btn" role="group">
                                    <span class="btn btn-white disabled fa fa-plus" title="Maximum allowed MACs. Delete a MAC address first."></span>
                                  </div>
                                <?php endif; ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach( $t->vli->layer2Addresses as $l2a ):?>
                            <?php $macColon = strtolower( $l2a->macFormatted( ':' ) ); ?>
                            <tr>
                                <td>
                                    <?= $l2a->macFormatted( ':' ) ?>
                                </td>
                                <td>
                                    <?= $l2a->created_at ?>
                                </td>
                                <td>
                                    <?php if( in_array( $macColon, $syncedMacs, true ) ): ?>
                                        <span class="badge badge-success" title="This MAC address is configured on the switch">
                                            <i class="fa fa-check"></i> On Switch
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" title="Not yet synced to the switch — press Sync to Switch">
                                            <i class="fa fa-clock-o"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a class="btn btn-white btn-view-l2a" id="view-l2a-<?= $l2a->id ?>" data-object-mac="<?= $l2a->mac ?>" href="#" title="View">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                        <?php if( $t->vli->layer2Addresses()->count() > config( 'ixp_fe.layer2-addresses.customer_params.min_addresses' ) ): ?>
                                           <a class="btn btn-white btn-delete" id='d2f-list-delete-<?= $l2a->id ?>' data-object-id="<?= $l2a->id ?>" href="<?= route( 'l2-address@delete' , [ 'l2a' => $l2a->id, 'showFeMessage' => true  ]  )  ?>"  title="Delete">
                                                <i class="fa fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach;?>
                   </tbody>
                </table>
            </div>
            <?= $t->insert( 'layer2-address/modal-mac' ); ?>
        </div>
    </div>

    <!-- MAC sync result modal -->
    <div class="modal fade" id="mac-sync-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sync to Switch</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="mac-sync-spinner" class="text-center py-4">
                        <i class="fa fa-spinner fa-spin fa-2x"></i>
                    </div>
                    <div id="mac-sync-result" style="display:none">
                        <div id="mac-sync-ok" class="alert alert-info" style="display:none"></div>
                        <div id="mac-sync-err" class="alert alert-warning" style="display:none"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <?= $t->insert( 'layer2-address/js/clipboard' ); ?>
    <?= $t->insert( 'layer2-address/js/vlan-interface' ); ?>
    <script>
    $( '#btn-mac-sync' ).on( 'click', function() {
        var btn = $( this );
        if ( btn.is( ':disabled' ) ) return;

        if ( !confirm( 'Queue your MAC address changes to be applied to the switch?' ) ) return;

        $( '#mac-sync-spinner' ).show();
        $( '#mac-sync-result' ).hide();
        $( '#mac-sync-ok' ).hide().text( '' );
        $( '#mac-sync-err' ).hide().text( '' );
        $( '#mac-sync-modal' ).modal( 'show' );

        $.ajax( {
            url:    '<?= route( 'mac-sync@customer-sync' ) ?>',
            method: 'POST',
            data:   { _token: btn.data( 'token' ), vli_id: btn.data( 'vli-id' ) },
            success: function( res ) {
                $( '#mac-sync-spinner' ).hide();
                $( '#mac-sync-result' ).show();
                if ( res.success ) {
                    $( '#mac-sync-ok' )
                        .text( 'Your changes have been queued and will be applied ' +
                               ( res.run_at_human || 'shortly' ) + '. This page will reload.' )
                        .show();
                    $( '#mac-sync-modal' ).on( 'hidden.bs.modal', function() {
                        location.reload();
                    } );
                } else {
                    $( '#mac-sync-err' ).text( res.error || 'Sync could not be queued.' ).show();
                }
            },
            error: function( xhr ) {
                $( '#mac-sync-spinner' ).hide();
                $( '#mac-sync-result' ).show();
                // Actually notify staff — the message below promises it. Best-effort,
                // fire-and-forget; the backend logs + emails MAC_SYNC_NOTIFY_EMAIL.
                $.ajax( {
                    url:    '<?= route( 'mac-sync@customer-report-failure' ) ?>',
                    method: 'POST',
                    data:   {
                        _token: btn.data( 'token' ),
                        vli_id: btn.data( 'vli-id' ),
                        status: xhr ? xhr.status : 0
                    }
                } );
                $( '#mac-sync-err' )
                    .text( 'Sync could not be completed — EdgeIX staff have been notified and will follow up.' )
                    .show();
            }
        } );
    } );
    </script>
<?php $this->append() ?>
