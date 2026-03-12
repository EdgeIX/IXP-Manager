<?php $this->layout('services/lg/layout') ?>

<?php $this->section('title') ?>
    <small>Routes for <?= ucwords( $t->source ) ?> <code><?= $t->name ?></code></small>
<?php $this->append() ?>

<?php $this->section('content') ?>

    <?php
        // Determine which tab is active based on source
        $isProtocol     = in_array( $t->source, [ 'protocol' ] );
        $isFiltered     = $t->source === 'filtered from protocol';
        $isNotExported  = $t->source === 'not exported to protocol';
        $isExport       = $t->source === 'export to protocol';
        $isTable        = $t->source === 'table';

        // Only show tabs when viewing protocol-based routes (not table or export views)
        $showTabs = $isProtocol || $isFiltered || $isNotExported;

        // Determine the protocol name for tab links
        $protocolName = $t->name;

        // Check if birdwatcher (supports not-exported endpoint)
        $isBirdwatcher = $t->lg->router()->apiType() === \IXP\Models\Router::API_TYPE_BIRDWATCHER;
    ?>

    <?php if( $showTabs ): ?>
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $isProtocol ? 'active' : '' ?>"
                   href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/protocol/<?= urlencode( $protocolName ) ?>">
                    Accepted
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $isFiltered ? 'active' : '' ?>"
                   href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/filtered/<?= urlencode( $protocolName ) ?>">
                    <i class="fa fa-exclamation-triangle"></i> Filtered
                </a>
            </li>
            <?php if( $isBirdwatcher ): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $isNotExported ? 'active' : '' ?>"
                       href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/routes/not-exported/<?= urlencode( $protocolName ) ?>">
                        Not Exported
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <?php if( $t->source ?? false ): ?>
                <b>Routes <?= $t->source === 'export to protocol' ? 'exported to protocol' : ( $t->source === 'filtered from protocol' ? 'filtered/rejected from protocol' : ( $t->source === 'not exported to protocol' ? 'not exported to protocol' : 'from ' . $t->source ) ) ?>: <code><?= $t->name ?></code>.</b>
            <?php endif; ?>

            <b>Key:</b> <span class="badge badge-success">P</span>
            - Primary / active route.
            <span class="badge badge-warning">N</span>
            - Inactive route.
            <i class="fa fa-exclamation-triangle"></i>
            - Blocked / filtered route.

            <div class="mt-2">
                <b>Filter:</b>
                <select id="filter-rpki" class="custom-select custom-select-sm d-inline-block" style="width: auto; font-size: 12px;">
                    <option value="">RPKI: All</option>
                    <option value="VALID">RPKI VALID</option>
                    <option value="UNKNOWN">RPKI UNKNOWN</option>
                    <option value="NOT CHECKED">RPKI NOT CHECKED</option>
                    <option value="INVALID">RPKI INVALID</option>
                </select>
                <select id="filter-irrdb" class="custom-select custom-select-sm d-inline-block" style="width: auto; font-size: 12px;">
                    <option value="">IRRDB: All</option>
                    <option value="VALID">IRRDB VALID</option>
                    <option value="INVALID">IRRDB INVALID</option>
                    <option value="NOT CHECKED">IRRDB NOT CHECKED</option>
                    <option value="MORE SPECIFIC">IRRDB MORE SPECIFIC</option>
                </select>
                <button id="filter-clear" class="btn btn-sm btn-outline-secondary" style="font-size: 12px;">Clear</button>
            </div>
        </div>
    </div>

    <table class="table table-striped table-sm text-monospace"  style="font-size: 14px;" id="routes">
        <thead class="thead-dark">
            <tr>
                <th>
                    Network
                </th>
                <th>
                    Next Hop
                </th>
                <th></th>
                <th>
                    Metric&nbsp;
                </th>
                <th>
                    RPKI
                </th>
                <th>
                    IRRDB
                </th>
                <th>
                    Communities?&nbsp;
                </th>
                <?php if( $isFiltered ): ?>
                    <th>
                        Reason
                    </th>
                <?php endif; ?>
                <th>
                    AS Path
                </th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if( count( $t->content->routes ) ): ?>
                <?php foreach( $t->content->routes as $r ): ?>
                    <?php
                        // Check for blocked routes and extract RPKI/IRRDB status from large communities
                        $blocked = false;
                        $rpki = null;
                        $irrdb = null;
                        $filterReasons = [];
                        if( isset( $r->bgp->large_communities ) ) {
                            foreach( $r->bgp->large_communities as $lc ) {
                                if( !is_array( $lc ) || count( $lc ) < 3 ) continue;
                                if( $lc[0] != $t->lg->router()->asn ) continue;

                                // Blocked/filtered: (ASN, 1101, reason)
                                if( $lc[1] == 1101 ) {
                                    $blocked = true;
                                    $reason = $t->bird()->translateBgpFilteringLargeCommunity( ':1101:' . $lc[2] );
                                    if( $reason ) {
                                        $filterReasons[] = $reason;
                                    }
                                }
                                // RPKI: (ASN, 1000, status)
                                if( $lc[1] == 1000 ) {
                                    switch( (int)$lc[2] ) {
                                        case 1: $rpki = [ 'VALID', 'success' ]; break;
                                        case 2: $rpki = [ 'UNKNOWN', 'info' ]; break;
                                        case 3: $rpki = [ 'NOT CHECKED', 'warning' ]; break;
                                    }
                                }
                                // IRRDB: (ASN, 1001, status)
                                if( $lc[1] == 1001 ) {
                                    switch( (int)$lc[2] ) {
                                        case 0: $irrdb = $irrdb ?? [ 'INVALID', 'info' ]; break;
                                        case 1: $irrdb = [ 'VALID', 'success' ]; break;
                                        case 2: $irrdb = $irrdb ?? [ 'NOT CHECKED', 'warning' ]; break;
                                        case 3: $irrdb = $irrdb ?? [ 'MORE SPECIFIC', 'info' ]; break;
                                    }
                                }
                            }
                        }
                    ?>

                    <tr>
                        <td>
                            <?php
                                list( $ip, $mask ) = explode( '/', $r->network );
                            ?>
                            <a href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/route/<?= urlencode($ip) ?>/<?= $mask ?>/table/master<?= (int)$t->lg->router()->software === \IXP\Models\Router::SOFTWARE_BIRD2 ? $t->lg->router()->protocol()[-1] : '' ?>"
                                    data-toggle="modal" data-target="#route-modal">
                                <?= $r->network ?>
                            </a>
                        </td>
                        <td>
                            <?= $r->gateway ?>
                        </td>
                        <td>
                            <?php if( $r->primary ): ?>
                                <span class="badge badge-success">P</span>
                            <?php else: ?>
                                <span class="badge badge-warning">N</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $r->metric ?></td>
                        <td>
                            <?php if( $rpki ): ?>
                                <span class="badge badge-<?= $rpki[1] ?>" style="font-size: 10px;"><?= $rpki[0] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if( $irrdb ): ?>
                                <span class="badge badge-<?= $irrdb[1] ?>" style="font-size: 10px;"><?= $irrdb[0] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-secondary">
                                <?php if( isset( $r->bgp->communities ) ): ?>
                                    <?= count( $r->bgp->communities ) ?>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </span>

                            <?php if( isset( $r->bgp->large_communities ) ): ?>
                                <span class="badge badge-secondary">LC:
                                    <?= count( $r->bgp->large_communities ) ?>
                                </span>

                                <?= !$blocked ? '' : '<i class="fa fa-exclamation-triangle"></i>' ?>
                            <?php endif; ?>
                        </td>
                        <?php if( $isFiltered ): ?>
                            <td>
                                <?php foreach( $filterReasons as $reason ): ?>
                                    <span class="badge badge-<?= $reason[1] ?>" style="font-size: 10px;"><?= $reason[0] ?></span>
                                <?php endforeach; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <?php if( isset( $r->bgp->as_path ) ): ?>
                                <?php foreach( $r->bgp->as_path as $asp ): ?>
                                    <?= $t->asNumber( $asp, false ) ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-white btn-sm" style="font-size: 14px;" data-toggle="modal"
                                href="<?= url('/lg') . '/' . $t->lg->router()->handle ?>/route/<?= urlencode( explode('/',$r->network)[0] ) ?>/<?= explode('/',$r->network)[1] ?>/<?= in_array( $t->source, [ 'export to protocol', 'not exported to protocol' ] ) ? 'export' : 'protocol' ?>/<?= $t->name ?>"
                                data-target="#route-modal">Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="modal fade" id="route-modal" role="dialog">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
        </div>
      </div>
    </div>
<?php $this->append() ?>

<?php $this->section('scripts') ?>
    <script type="text/javascript">
        $('#routes').removeClass( 'display' ).addClass( 'table' );

        // Column indices for RPKI and IRRDB
        const RPKI_COL = 4;
        const IRRDB_COL = 5;

        // Custom DataTables search filter for RPKI/IRRDB dropdowns
        $.fn.dataTable.ext.search.push(function( settings, data, dataIndex ) {
            let rpkiFilter = $('#filter-rpki').val();
            let irrdbFilter = $('#filter-irrdb').val();

            if( rpkiFilter && data[RPKI_COL].trim().indexOf( rpkiFilter ) === -1 ) {
                return false;
            }
            if( irrdbFilter && data[IRRDB_COL].trim().indexOf( irrdbFilter ) === -1 ) {
                return false;
            }
            return true;
        });

        $(document).ready(function() {
            let table = $('#routes').DataTable({
                stateSave: true,
                stateDuration : DATATABLE_STATE_DURATION,
                paging: false,
                order: [[ 0, "asc" ]],
                columnDefs: [
                    { type: 'ip-address', targets: 0 },
                    { type: 'ip-address', targets: 1 },
                    { type: 'string', targets: 2 },
                    { type: 'num', targets: 3 },
                    { type: 'string', targets: 4 },
                    { type: 'string', targets: 5 },
                    { type: 'string', targets: 6 },
                    { orderable: false, targets: -1 },
                ],
                language: {
                    emptyTable: 'No routes found'
                }
            });

            // Redraw table when filters change
            $('#filter-rpki, #filter-irrdb').on('change', function() {
                table.draw();
            });

            $('#filter-clear').on('click', function() {
                $('#filter-rpki').val('');
                $('#filter-irrdb').val('');
                table.draw();
            });

            $('body').on('click', '[data-toggle="modal"]', function() {
                $( $( this ).data( "target" )+' .modal-content').html( `
                    <div class="text-center">
                        <div class="spinner-border m-5" style="width: 5rem; height: 5rem;" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                ` );

                $( $( this ).data( "target" ) + ' .modal-content').load( $( this ).attr( 'href' ) );
            });
        });

    </script>

<?php $this->append() ?>
