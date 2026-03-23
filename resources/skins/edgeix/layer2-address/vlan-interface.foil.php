<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );

    // Load MAC sync state for this VlanInterface (ixpm-mac-sync package).
    // Guard: package may not be installed yet.
    $macSyncEnabled = class_exists( \EdgeIX\IxpmMacSync\Models\MacSyncState::class );
    $syncState      = $macSyncEnabled
        ? \EdgeIX\IxpmMacSync\Models\MacSyncState::where( 'vlan_interface_id', $t->vli->id )->first()
        : null;
    $currentMacs = $t->vli->layer2Addresses->map( fn($l2) => strtolower( $l2->macFormatted(':') ) )->filter()->values()->all();
    $syncedMacs  = $syncState?->synced_macs ?? [];
    $macsAdd     = array_diff( $currentMacs, $syncedMacs );
    $macsRemove  = array_diff( $syncedMacs, $currentMacs );
    $inSync      = empty( $macsAdd ) && empty( $macsRemove );
    $isThrottled = $syncState?->isThrottled() ?? false;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    VLAN Interface / Configured MAC Address Management
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="d-flex align-items-center">
        <?php if( $macSyncEnabled && $inSync ): ?>
            <span class="badge badge-success mr-3" title="Switch ACL matches IXP-Manager">
                <i class="fa fa-check-circle"></i> In Sync
            </span>
        <?php elseif( $macSyncEnabled && $syncState ): ?>
            <span class="badge badge-warning mr-3" title="<?= count($macsAdd) ?> to add, <?= count($macsRemove) ?> to remove">
                <i class="fa fa-exclamation-triangle"></i> Changes Pending
            </span>
        <?php elseif( $macSyncEnabled ): ?>
            <span class="badge badge-secondary mr-3" title="Never synced via MAC Sync">
                <i class="fa fa-question-circle"></i> Not Synced
            </span>
        <?php endif; ?>

        <?php if( $macSyncEnabled ): ?>
        <div class="btn-group btn-sm mr-2">
            <button class="btn btn-sm btn-white" id="btn-mac-preview"
                    data-vli-id="<?= $t->vli->id ?>"
                    data-token="<?= csrf_token() ?>">
                <i class="fa fa-eye"></i> Preview
            </button>
            <button class="btn btn-sm btn-primary <?= $isThrottled ? 'disabled' : '' ?>" id="btn-mac-apply"
                    data-vli-id="<?= $t->vli->id ?>"
                    data-token="<?= csrf_token() ?>"
                    <?php if( $isThrottled ): ?>
                        disabled title="Throttled until <?= $syncState->throttle_until->format('H:i:s') ?>"
                    <?php endif; ?>>
                <i class="fa fa-upload"></i> Sync to Switch
            </button>
        </div>
        <?php endif; ?>

        <div class="btn-group btn-sm">
            <a href="<?= route( 'virtual-interface@edit', [ 'vi' => $t->vli->virtualInterface->id ] ) ?>" class="btn btn-sm btn-white">
                Virtual Interface Details
            </a>
        </div>
    </div>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-sm-12">
            <?= $t->alerts() ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h3>
                        Configured MAC Address Management
                        <small>for <?= $t->ee( $t->vli->virtualInterface->customer->name ) ?>'s VLAN Interface:</small>
                    </h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-2">
                            VLAN
                        </dt>
                        <dd class="col-sm-9">
                            <?= $t->ee( $t->vli->vlan->name ) ?>
                        </dd>
                        <dt class="col-sm-2">
                            IP Addresses
                        </dt>
                        <dd class="col-sm-9">
                            <?= $t->vli->ipv4Address ? $t->vli->ipv4Address->address . ( $t->vli->ipv6Address ? ' / ': '' ) : ''  ?>
                            <?= $t->vli->ipv6Address->address ?? '' ?>
                        </dd>
                        <?php if( $syncState ): ?>
                        <dt class="col-sm-2">Last Synced</dt>
                        <dd class="col-sm-9">
                            <?= $t->ee( $syncState->last_synced_at?->diffForHumans() ?? 'Never' ) ?>
                        </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <div id="message"></div>

            <div id="list-area" class="collapse">
                <table id='layer-2-interface-list' class="table table-striped w-100">
                    <thead class="thead-dark">
                        <tr>
                            <th>MAC Address</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Action <a class="btn btn-sm btn-white" href="#" id="add-l2a"><i class="fa fa-plus"></i></a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach( $t->vli->layer2Addresses as $l2a ):?>
                            <tr>
                                <td><?= $l2a->macFormatted( ':' ) ?></td>
                                <td><?= $l2a->created_at ?></td>
                                <td><?= $l2a->updated_at ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a class="btn btn-white btn-view-l2a" id="view-l2a-<?= $l2a->id ?>" data-object-mac="<?= $l2a->mac ?>" href="#" title="View">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                        <a class="btn btn-white btn-delete" data-object-id="<?= $l2a->id ?>" href="<?= route( 'l2-address@delete' , [ 'l2a' => $l2a->id, 'showFeMessage' => true  ]  )  ?>" title="Delete">
                                            <i class="fa fa-trash"></i>
                                        </a>
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

    <?php if( $macSyncEnabled ): ?>
    <!-- MAC Sync diff/result modal -->
    <div class="modal fade" id="mac-sync-modal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mac-sync-modal-title">MAC Sync</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="mac-sync-spinner" class="text-center py-4">
                        <i class="fa fa-spinner fa-spin fa-2x"></i>
                    </div>
                    <div id="mac-sync-result" style="display:none">
                        <div id="mac-sync-error" class="alert alert-danger" style="display:none"></div>
                        <div id="mac-sync-no-changes" class="alert alert-info" style="display:none">
                            No changes needed — switch is already up to date.
                        </div>
                        <div id="mac-sync-applied" class="alert alert-success" style="display:none">
                            <i class="fa fa-check"></i> Changes applied successfully.
                        </div>
                        <div id="mac-sync-diff-wrap" style="display:none">
                            <h6>Config diff</h6>
                            <pre id="mac-sync-diff" class="bg-light p-3" style="font-size:12px; max-height:400px; overflow-y:auto;"></pre>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; // $macSyncEnabled ?>
<?php $this->append() ?>

<?php $this->section( 'scripts' ) ?>
    <?= $t->insert( 'layer2-address/js/clipboard' ); ?>
    <?= $t->insert( 'layer2-address/js/vlan-interface' ); ?>
    <?php if( $macSyncEnabled ): ?>
    <script>
    var macSyncPreviewUrl = '<?= route( 'mac-sync@preview' ) ?>';
    var macSyncApplyUrl   = '<?= route( 'mac-sync@apply' ) ?>';

    function macSyncShowModal( title ) {
        $( '#mac-sync-modal-title' ).text( title );
        $( '#mac-sync-spinner' ).show();
        $( '#mac-sync-result' ).hide();
        $( '#mac-sync-error' ).hide().text('');
        $( '#mac-sync-no-changes' ).hide();
        $( '#mac-sync-applied' ).hide();
        $( '#mac-sync-diff-wrap' ).hide();
        $( '#mac-sync-diff' ).text('');
        $( '#mac-sync-modal' ).modal('show');
    }

    function macSyncShowResult( res, applied ) {
        $( '#mac-sync-spinner' ).hide();
        $( '#mac-sync-result' ).show();

        if ( !res.success ) {
            $( '#mac-sync-error' ).text( res.error || 'Unknown error' ).show();
            return;
        }
        if ( !res.diff ) {
            $( '#mac-sync-no-changes' ).show();
            return;
        }
        if ( applied ) {
            $( '#mac-sync-applied' ).show();
        }
        $( '#mac-sync-diff' ).text( res.diff );
        $( '#mac-sync-diff-wrap' ).show();
    }

    $( '#btn-mac-preview' ).on( 'click', function() {
        var vliId = $( this ).data('vli-id');
        var token = $( this ).data('token');
        macSyncShowModal('Preview — no changes will be made');
        $.ajax({
            url:    macSyncPreviewUrl,
            method: 'POST',
            data:   { _token: token, vli_id: vliId },
            success: function( res ) { macSyncShowResult( res, false ); },
            error:   function( xhr ) {
                var msg = 'Request failed.';
                try { msg = xhr.responseJSON.error || msg; } catch(e) {}
                macSyncShowResult( { success: false, error: msg }, false );
            }
        });
    });

    $( '#btn-mac-apply' ).on( 'click', function() {
        var btn   = $( this );
        var vliId = btn.data('vli-id');
        var token = btn.data('token');
        if ( !confirm('Sync MAC addresses to the switch now?') ) return;
        macSyncShowModal('Applying Changes...');
        $.ajax({
            url:    macSyncApplyUrl,
            method: 'POST',
            data:   { _token: token, vli_id: vliId },
            success: function( res ) {
                macSyncShowResult( res, true );
                if ( res.success ) {
                    $( '#mac-sync-modal' ).on( 'hidden.bs.modal', function() {
                        location.reload();
                    });
                }
            },
            error: function( xhr ) {
                var msg = 'Request failed.';
                try { msg = xhr.responseJSON.error || msg; } catch(e) {}
                macSyncShowResult( { success: false, error: msg }, false );
            }
        });
    });
    </script>
    <?php endif; // $macSyncEnabled ?>
<?php $this->append() ?>
