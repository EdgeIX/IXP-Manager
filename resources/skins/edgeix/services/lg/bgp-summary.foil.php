<?php $this->layout('services/lg/layout') ?>
    <?php $this->section('title') ?>
        <small>BGP Protocol Summary</small>
    <?php $this->append() ?>
<?php $this->section('content') ?>
    <table class="table table-striped table-sm text-monospace" style="font-size: 14px;width: 100%;" id="bgpsummary">
        <thead class="thead-dark">
            <tr>
                <th>
                    Neighbor
                </th>
                <th>
                    Description
                </th>
                <th class="text-right">
                    ASN&nbsp;
                </th>
                <th>
                    Table
                </th>
                <th class="text-right">
                    PfxLimit&nbsp;
                </th>
                <th>
                    State
                </th>
                <th>
                    Uptime
                </th>
                <th class="text-right">
                    PfxRcd&nbsp;
                </th>
                <th class="text-right">
                    Filtered&nbsp;
                </th>
                <th class="text-right">
                    PfxExp&nbsp;
                </th>
                <th>
                    Actions
                </th>
            </tr>
        </thead>
        <tbody>
            <?php foreach( $t->content->protocols as $name => $p ): ?>
                <?php
                    // Calculate uptime from state_changed
                    $uptime = '';
                    if( isset( $p->state_changed ) && $p->state_changed ) {
                        try {
                            $changed = str_contains( $p->state_changed, 'T' )
                                ? new DateTime( $p->state_changed )
                                : DateTime::createFromFormat( 'Y-m-d H:i:s', $p->state_changed );
                            if( $changed ) {
                                $diff = (new DateTime())->diff( $changed );
                                if( $diff->days > 0 ) {
                                    $uptime = $diff->days . 'd ' . $diff->h . 'h';
                                } elseif( $diff->h > 0 ) {
                                    $uptime = $diff->h . 'h ' . $diff->i . 'm';
                                } elseif( $diff->i > 0 ) {
                                    $uptime = $diff->i . 'm ' . $diff->s . 's';
                                } else {
                                    $uptime = $diff->s . 's';
                                }
                            }
                        } catch( Exception $e ) {
                            $uptime = '';
                        }
                    }
                ?>
                <tr <?= $p->state === 'up' ? '' : 'class="warning"' ?>>
                    <td class="pr-4">
                        <?=$p->neighbor_address?>
                    </td>
                    <td class="pr-4">
                        <?= ( $p->description_short ?? false ) ? $t->ee( $p->description_short ) : $t->ee( $p->description ?? "" ) ?>
                    </td>
                    <td class="text-right pr-4" data-order="<?= $p->neighbor_as ?>">
                        <?= $t->asNumber( $p->neighbor_as, false ) ?>
                    </td>
                    <td>
                        <a href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/table/<?= $p->table ?>">
                            <?= $p->table ?>
                        </a>
                    </td>
                    <?php if( isset($p->import_limit) and isset( $p->route_limit_at ) and $p->import_limit ): ?>
                        <td class="text-right pr-4" data-order="<?= $p->import_limit ?>">
                            <span
                                <?php if( ( (float)$p->route_limit_at / $p->import_limit ) >= .9 ): ?>
                                    class="badge badge-danger"
                                <?php elseif( ( (float)$p->route_limit_at / $p->import_limit ) >= .8 ): ?>
                                    class="badge badge-warning"
                                <?php endif; ?>
                            >
                                <?= $p->route_limit_at ?>/<?= $p->import_limit ?>
                            </span>
                    <?php else: ?>
                        <td class="text-right pr-4">
                    <?php endif; ?>
                    </td>
                    <td data-order="<?= $p->state === 'up' ? 1 : 0 ?>">
                        <?php if( $p->state === 'up' ): ?>
                            <span class="badge badge-success">up</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><?= $p->bgp_state ?? 'down' ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-order="<?= isset( $p->state_changed ) ? strtotime( $p->state_changed ) : 0 ?>">
                        <?= $uptime ?>
                    </td>
                    <td class="text-right pr-4" data-order="<?= $p->state !== 'up' ? "-1" : $p->routes->imported ?>">
                        <?php if( $p->state === 'up' ): ?>
                            <?php if( is_int( $p->routes->imported ) && is_int( $t->content->api->max_routes ) && $p->routes->imported < $t->content->api->max_routes ): ?>
                                <a href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/protocol/<?= $name ?>">
                            <?php endif; ?>
                            <?= $p->routes->imported ?>
                            <?php if( is_int( $p->routes->imported ) && is_int( $t->content->api->max_routes ) && $p->routes->imported < $t->content->api->max_routes ): ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-right pr-4" data-order="<?= $p->state !== 'up' ? -1 : ( $p->routes->filtered ?? 0 ) ?>">
                        <?php if( $p->state === 'up' ): ?>
                            <?php if( isset( $p->routes->filtered ) && $p->routes->filtered > 0 ): ?>
                                <a href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/filtered/<?= $name ?>">
                                    <span class="badge badge-danger"><?= $p->routes->filtered ?></span>
                                </a>
                            <?php else: ?>
                                <a href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/filtered/<?= $name ?>"
                                   class="text-muted" title="View filtered routes">
                                    <i class="fa fa-search fa-sm"></i>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-right pr-4" data-order="<?= $p->state === 'up' ? $p->routes->exported : -1 ?>">
                        <?php if( $p->state === 'up' ): ?>
                            <?php if( is_int( $p->routes->exported ) && is_int( $t->content->api->max_routes ) && $p->routes->exported < $t->content->api->max_routes ): ?>
                                <a href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/export/<?= $name ?>">
                            <?php endif; ?>
                            <?= $p->routes->exported ?>
                            <?php if( is_int( $p->routes->exported ) && is_int( $t->content->api->max_routes ) && $p->routes->exported < $t->content->api->max_routes ): ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-reset">
                        <a class="btn btn-white btn-sm" style="font-size: 14px;" id="protocol_details-<?= $name ?>"
                            data-protocol="<?= $name ?>" title="<?= $t->ee( $p->description ) ?? "" ?>">
                            Details
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<div class="modal fade" id="protocol-info-modal" role="dialog">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="modal-title" id="myModalLabel">
                Protocol Details for <code><span id="title_p_name"></span></code>
            </h4>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="modal-body">
            <pre>
<span id="p_name"></span>    <span id="p_bird_protocol"></span>    <span id="p_table"></span> <span id="p_state"></span>     <span id="p_state_changed"></span>  <span id="p_connection"></span>
  Description:    <span id="p_description"></span>
  Preference:     <span id="p_preference"></span>
  Input filter:   <span id="p_input_filter"></span>
  Output filter:  <span id="p_output_filter"></span><span id="p_o_import_limit">
  Import limit:   <span id="p_import_limit"></span>
    Action:       <span id="p_limit_action"></span></span>
  Routes:         <span id="p_routes_imported"></span> imported, <span id="p_routes_exported"></span> exported, <span id="p_routes_preferred"></span> preferred
  Route change stats:     received   rejected   filtered    ignored   accepted
    Import updates:     <span id="p_import_updates_received"></span> <span id="p_import_updates_rejected"></span> <span id="p_import_updates_filtered"></span> <span id="p_import_updates_ignored"></span> <span id="p_import_updates_accepted"></span>
    Import withdraws:   <span id="p_import_withdraws_received"></span> <span id="p_import_withdraws_rejected"></span>        --- <span id="p_import_withdraws_ignored"></span> <span id="p_import_withdraws_accepted"></span>
    Export updates:     <span id="p_export_updates_received"></span> <span id="p_export_updates_rejected"></span> <span id="p_export_updates_filtered"></span>        --- <span id="p_export_updates_accepted"></span>
    Export withdraws:   <span id="p_export_withdraws_received"></span>        ---        ---        --- <span id="p_export_withdraws_accepted"></span>
  BGP state:          <span id="p_bgp_state"></span>
    Neighbor address: <span id="p_neighbor_address"></span>
    Neighbor AS:      <span id="p_neighbor_as"></span>
    Neighbor ID:      <span id="p_neighbor_id"></span>
    Neighbor caps:    <span id="p_neighbor_capabilities"></span>
    Session:          <span id="p_bgp_session"></span>
    Source address:   <span id="p_source_address"></span><span id="p_o_route_limit_at">
    Route limit:      <span id="p_route_limit_at"></span>/<span id="p_import_limit2"></span></span>
    Hold timer:       <span id="p_hold_timer"></span>
    Keepalive timer:  <span id="p_keepalive"></span>
</pre>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        </div>
    </div>
  </div>
</div>

<?php $this->append() ?>

<?php $this->section('scripts') ?>

<script type="text/javascript">

    // http://stackoverflow.com/questions/12449890/reload-content-in-modal-twitter-bootstrap
    $(document).on('hidden.bs.modal', function (e) {
        $(e.target).removeData('bs.modal');
    });

    let protocols = <?= json_encode($t->content->protocols) ?>;
    function spacifyNumber( n, s ) {
        let str = String(n);
        return ' '.repeat( Math.max( 0, s - str.length ) ) + str;
    }
    $('#bgpsummary').removeClass( 'display' ).addClass('table');

    $(document).ready(function() {

        $('#bgpsummary').DataTable({
            stateSave: true,
            stateDuration : DATATABLE_STATE_DURATION,
            responsive: true,
            paging: false,
            order: [[ 2, "asc" ]],
            columnDefs: [
                { type: 'ip-address', targets: [ 0 ] },
                { type: 'string', targets: [ 1 ] },
                { type: 'num', targets: [ 2 ] },
                { type: 'string', targets: [ 3 ], "orderable": false },
                { type: 'num', targets: [ 4 ] },
                { type: 'string', targets: [ 5 ] },
                { type: 'num', targets: [ 6 ] },
                { type: 'num', targets: [ 7 ] },
                { type: 'num', targets: [ 8 ] },
                { type: 'num', targets: [ 9 ] },
                {},
            ],
            language: {
                emptyTable: 'No BGP sessions found'
            },
        });
    });

    $('a[id|="protocol_details"]').on( 'click', function(){
        let pname = $(this).attr('data-protocol');
        let p = protocols[pname];
        $('#title_p_name'   ).text( pname );
        $('#p_name'         ).text( pname );
        $('#p_bird_protocol').text( p.bird_protocol );
        $('#p_table'        ).text( p.table );
        $('#p_state'        ).text( p.state );
        $('#p_state_changed').text( p.state_changed );
        $('#p_connection'   ).text( p.connection );
        $('#p_description'  ).text( p.description );
        $('#p_preference'   ).text( p.preference );
        $('#p_input_filter' ).text( p.input_filter );
        $('#p_output_filter').text( p.output_filter );
        if( p.import_limit ) {
            $('#p_import_limit').text(p.import_limit);
            $('#p_o_import_limit').show();
            $('#p_o_route_limit_at').show();
        } else {
            $('#p_o_import_limit').hide();
            $('#p_o_route_limit_at').hide();
        }
        $('#p_limit_action' ).text( p.limit_action );
        $('#p_routes_imported'  ).text( p.routes ? ( p.routes.imported ?? 0 ) : 0 );
        $('#p_routes_exported'  ).text( p.routes ? ( p.routes.exported ?? 0 ) : 0 );
        $('#p_routes_preferred' ).text( p.routes ? ( p.routes.preferred ?? 0 ) : 0 );
        $('#p_import_updates_received'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.import_updates.received ?? 0) : 0, 10 ) );
        $('#p_import_updates_rejected'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.import_updates.rejected ?? 0) : 0, 10 ) );
        $('#p_import_updates_filtered'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.import_updates.filtered ?? 0) : 0, 10 ) );
        $('#p_import_updates_ignored'   ).text( spacifyNumber( p.route_changes ? (p.route_changes.import_updates.ignored ?? 0) : 0, 10 ) );
        $('#p_import_updates_accepted'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.import_updates.accepted ?? 0) : 0, 10 ) );
        $('#p_import_withdraws_received').text( spacifyNumber( p.route_changes ? (p.route_changes.import_withdraws.received ?? 0) : 0, 10 ) );
        $('#p_import_withdraws_rejected').text( spacifyNumber( p.route_changes ? (p.route_changes.import_withdraws.rejected ?? 0) : 0, 10 ) );
        $('#p_import_withdraws_ignored' ).text( spacifyNumber( p.route_changes ? (p.route_changes.import_withdraws.ignored ?? 0) : 0, 10 ) );
        $('#p_import_withdraws_accepted').text( spacifyNumber( p.route_changes ? (p.route_changes.import_withdraws.accepted ?? 0) : 0, 10 ) );
        $('#p_export_updates_received'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.export_updates.received ?? 0) : 0, 10 ) );
        $('#p_export_updates_rejected'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.export_updates.rejected ?? 0) : 0, 10 ) );
        $('#p_export_updates_filtered'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.export_updates.filtered ?? 0) : 0, 10 ) );
        $('#p_export_updates_accepted'  ).text( spacifyNumber( p.route_changes ? (p.route_changes.export_updates.accepted ?? 0) : 0, 10 ) );
        $('#p_export_withdraws_received').text( spacifyNumber( p.route_changes ? (p.route_changes.export_withdraws.received ?? 0) : 0, 10 ) );
        $('#p_export_withdraws_accepted').text( spacifyNumber( p.route_changes ? (p.route_changes.export_withdraws.accepted ?? 0) : 0, 10 ) );
        $('#p_bgp_state'            ).text( p.bgp_state );
        $('#p_neighbor_address'     ).text( p.neighbor_address );
        $('#p_neighbor_as'          ).text( p.neighbor_as );
        $('#p_neighbor_id'          ).text( p.neighbor_id );
        if( p.neighbor_capabilities instanceof Array && p.neighbor_capabilities.length ) {
            $('#p_neighbor_capabilities').text( p.neighbor_capabilities.join(' ') );
        } else {
            $('#p_neighbor_capabilities').text( 'n/a' );
        }
        if( p.bgp_session instanceof Array && p.bgp_session.length ) {
            $('#p_bgp_session').text( p.bgp_session.join(' ') );
        } else {
            $('#p_bgp_session').text( 'n/a' );
        }
        $('#p_source_address'   ).text( p.source_address );
        $('#p_route_limit_at'   ).text( p.route_limit_at );
        $('#p_import_limit2'    ).text( p.import_limit );
        $('#p_hold_timer'       ).text( p.hold_timer );
        $('#p_keepalive'        ).text( p.keepalive );
        $('#protocol-info-modal').modal('show', {backdrop: 'static'});
    });
</script>

<?php $this->append() ?>
