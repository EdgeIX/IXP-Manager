<?php
    /**
     * EdgIX skin: Customer overview — Pseudowires tab.
     *
     * Shows pseudowire circuits where this customer is the requester (ordered)
     * or the target (received). Active circuits have expandable rows with
     * inline traffic graphs (when metrics URL is configured).
     */

    use EdgeIX\IxpmPseudowire\Models\PwCircuit;
    use Illuminate\Support\Facades\Auth;

    $c = $t->c; /** @var \IXP\Models\Customer $c */

    $hasMetrics = (bool) config( 'pseudowire.metrics.url' );
    $colCount   = $hasMetrics ? 8 : 7;

    // Determine which traffic endpoint to use based on viewer context
    $isSuperUser = Auth::check() && Auth::getUser()->isSuperUser();

    $badgeMap = [
        'pending_approval'   => 'warning',
        'approved'           => 'info',
        'provisioning'       => 'info',
        'active'             => 'success',
        'teardown_requested' => 'warning',
        'deprovisioning'     => 'warning',
        'deprovisioned'      => 'secondary',
        'rejected'           => 'danger',
        'cancelled'          => 'secondary',
        'failed'             => 'danger',
    ];

    $activeStates = [ 'active', 'teardown_requested' ];

    // Circuits this customer ordered (A-End / requester)
    $ordered = PwCircuit::where( 'requester_customer_id', $c->id )
        ->with( [ 'targetCustomer', 'requesterVirtualInterface.physicalInterfaces.switchPort.switcher', 'targetVirtualInterface.physicalInterfaces.switchPort.switcher' ] )
        ->orderByDesc( 'created_at' )
        ->get();

    // Circuits targeting this customer (Z-End / received)
    $received = PwCircuit::where( 'target_customer_id', $c->id )
        ->where( 'requester_customer_id', '!=', $c->id ) // exclude self-connects from appearing twice
        ->with( [ 'requesterCustomer', 'requesterVirtualInterface.physicalInterfaces.switchPort.switcher', 'targetVirtualInterface.physicalInterfaces.switchPort.switcher' ] )
        ->orderByDesc( 'created_at' )
        ->get();

    // Helper: build a port label from a VirtualInterface
    $portLabel = function( $vi ) {
        if( !$vi ) return '—';
        $pi = $vi->physicalInterfaces->first();
        if( !$pi || !$pi->switchPort || !$pi->switchPort->switcher ) return '—';
        return $pi->switchPort->switcher->name . ' :: ' . $pi->switchPort->name;
    };

    // Helper: build the correct traffic URL for a circuit
    $trafficRoute = function( $pw ) use ( $isSuperUser ) {
        if ( $isSuperUser ) {
            return route( 'pw-admin@traffic', [ 'id' => $pw->id ] );
        }
        return route( 'pw@traffic', [ 'id' => $pw->id ] );
    };

    // Terminal states that are no longer "live"
    $terminalStates = [ 'deprovisioned', 'rejected', 'cancelled' ];

    // Check if customer has any eligible ports for pseudowires:
    // - 802.1q trunk (tagged), not reseller sub-rate, has physical interfaces
    $eligiblePorts = $c->virtualInterfaces->filter( function( $vi ) {
        if( !$vi->trunk ) return false;
        if( method_exists( $vi, 'isResellerSubRate' ) && $vi->isResellerSubRate() ) return false;
        if( $vi->physicalInterfaces->isEmpty() ) return false;
        return true;
    } );

    $isEligible = $eligiblePorts->isNotEmpty() && $c->status === \IXP\Models\Customer::STATUS_NORMAL;
?>

<?php if( $isEligible ): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="<?= route( 'pw@dashboard' ) ?>" class="btn btn-sm btn-outline-secondary mr-1">
                <i class="fa fa-tachometer-alt"></i> Pseudowire Dashboard
            </a>
            <a href="<?= route( 'pw-opt-in@list' ) ?>" class="btn btn-sm btn-outline-secondary mr-1">
                <i class="fa fa-cog"></i> Port Settings
            </a>
        </div>
        <a href="<?= route( 'pw-request@create' ) ?>" class="btn btn-sm btn-success">
            <i class="fa fa-plus"></i> Request New Pseudowire
        </a>
    </div>
<?php else: ?>
    <div class="alert alert-info mb-3">
        <i class="fa fa-info-circle"></i>
        Pseudowire services require an 802.1q tagged port.
        If you'd like to use pseudowires, please <a href="mailto:<?= config( 'identity.support_email', config( 'identity.email' ) ) ?>">contact us</a> to discuss your options.
    </div>
<?php endif; ?>

<?php if( $ordered->isEmpty() && $received->isEmpty() ): ?>
    <p class="text-muted">No pseudowire circuits found for this account.</p>
<?php else: ?>

    <?php if( $ordered->count() > 0 ): ?>
        <h5 class="mb-3">Ordered Circuits <small class="text-muted">(you are A-End)</small></h5>
        <table id="table-pw-ordered" class="table table-striped tw-shadow-md w-100">
            <thead class="thead-dark">
                <tr>
                    <?php if( $hasMetrics ): ?>
                        <th style="width:30px;"></th>
                    <?php endif; ?>
                    <th>Service ID</th>
                    <th>Status</th>
                    <th>Your Port</th>
                    <th>Remote Party</th>
                    <th>Remote Port</th>
                    <th>Bandwidth</th>
                    <th>Requested</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach( $ordered as $pw ): ?>
                    <?php $canExpand = $hasMetrics && in_array( $pw->state, $activeStates ); ?>
                    <tr class="<?= in_array( $pw->state, $terminalStates ) ? 'text-muted' : '' ?> <?= $canExpand ? 'pw-ov-expandable' : '' ?>"
                        <?php if( $canExpand ): ?>
                            data-pw-id="<?= $pw->id ?>"
                            data-traffic-url="<?= $trafficRoute( $pw ) ?>"
                            data-side="requester"
                            style="cursor:pointer;"
                        <?php endif; ?>
                    >
                        <?php if( $hasMetrics ): ?>
                            <td>
                                <?php if( $canExpand ): ?>
                                    <i class="fa fa-chevron-right pw-ov-toggle" style="transition:transform 0.2s;"></i>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td><?= $pw->service_id ?? '#' . $pw->id ?></td>
                        <td>
                            <span class="badge badge-<?= $badgeMap[ $pw->state ] ?? 'secondary' ?>">
                                <?= strtoupper( str_replace( '_', ' ', $pw->state ) ) ?>
                            </span>
                        </td>
                        <td>
                            <?= $portLabel( $pw->requesterVirtualInterface ) ?>
                            <?php if( $pw->requester_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->requester_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $t->ee( $pw->targetCustomer->name ?? '—' ) ?>
                            <?php if( $pw->targetCustomer ): ?>
                                <br><small class="text-muted">AS<?= $pw->targetCustomer->autsys ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $portLabel( $pw->targetVirtualInterface ) ?>
                            <?php if( $pw->target_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->target_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $pw->bandwidth_mbps ? $pw->bandwidth_mbps . ' Mbps' : '—' ?></td>
                        <td><?= $pw->requested_at ? $pw->requested_at->format( 'Y-m-d' ) : $pw->created_at->format( 'Y-m-d' ) ?></td>
                    </tr>
                    <?php if( $canExpand ): ?>
                        <tr class="pw-ov-detail" id="pw-ov-detail-<?= $pw->id ?>" style="display:none;">
                            <td colspan="<?= $colCount ?>" class="p-0 border-top-0 bg-light">
                                <div id="pw-ov-content-<?= $pw->id ?>"></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if( $received->count() > 0 ): ?>
        <?php if( $ordered->count() > 0 ): ?>
            <hr class="my-4">
        <?php endif; ?>
        <h5 class="mb-3">Received Circuits <small class="text-muted">(you are Z-End)</small></h5>
        <table id="table-pw-received" class="table table-striped tw-shadow-md w-100">
            <thead class="thead-dark">
                <tr>
                    <?php if( $hasMetrics ): ?>
                        <th style="width:30px;"></th>
                    <?php endif; ?>
                    <th>Service ID</th>
                    <th>Status</th>
                    <th>Your Port</th>
                    <th>Remote Party</th>
                    <th>Remote Port</th>
                    <th>Bandwidth</th>
                    <th>Requested</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach( $received as $pw ): ?>
                    <?php $canExpand = $hasMetrics && in_array( $pw->state, $activeStates ); ?>
                    <tr class="<?= in_array( $pw->state, $terminalStates ) ? 'text-muted' : '' ?> <?= $canExpand ? 'pw-ov-expandable' : '' ?>"
                        <?php if( $canExpand ): ?>
                            data-pw-id="<?= $pw->id ?>"
                            data-traffic-url="<?= $trafficRoute( $pw ) ?>"
                            data-side="target"
                            style="cursor:pointer;"
                        <?php endif; ?>
                    >
                        <?php if( $hasMetrics ): ?>
                            <td>
                                <?php if( $canExpand ): ?>
                                    <i class="fa fa-chevron-right pw-ov-toggle" style="transition:transform 0.2s;"></i>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td><?= $pw->service_id ?? '#' . $pw->id ?></td>
                        <td>
                            <span class="badge badge-<?= $badgeMap[ $pw->state ] ?? 'secondary' ?>">
                                <?= strtoupper( str_replace( '_', ' ', $pw->state ) ) ?>
                            </span>
                        </td>
                        <td>
                            <?= $portLabel( $pw->targetVirtualInterface ) ?>
                            <?php if( $pw->target_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->target_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $t->ee( $pw->requesterCustomer->name ?? '—' ) ?>
                            <?php if( $pw->requesterCustomer ): ?>
                                <br><small class="text-muted">AS<?= $pw->requesterCustomer->autsys ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $portLabel( $pw->requesterVirtualInterface ) ?>
                            <?php if( $pw->requester_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->requester_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $pw->bandwidth_mbps ? $pw->bandwidth_mbps . ' Mbps' : '—' ?></td>
                        <td><?= $pw->requested_at ? $pw->requested_at->format( 'Y-m-d' ) : $pw->created_at->format( 'Y-m-d' ) ?></td>
                    </tr>
                    <?php if( $canExpand ): ?>
                        <tr class="pw-ov-detail" id="pw-ov-detail-<?= $pw->id ?>" style="display:none;">
                            <td colspan="<?= $colCount ?>" class="p-0 border-top-0 bg-light">
                                <div id="pw-ov-content-<?= $pw->id ?>"></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php endif; ?>

<?php if( $hasMetrics && ( $ordered->count() > 0 || $received->count() > 0 ) ): ?>
<script>
document.addEventListener( 'DOMContentLoaded', function() {
    var loadedGraphs = {};
    var rows = document.querySelectorAll( '.pw-ov-expandable' );

    rows.forEach( function( row ) {
        row.addEventListener( 'click', function() {
            var id       = row.getAttribute( 'data-pw-id' );
            var detail   = document.getElementById( 'pw-ov-detail-' + id );
            var icon     = row.querySelector( '.pw-ov-toggle' );
            var content  = document.getElementById( 'pw-ov-content-' + id );

            if ( detail.style.display === 'table-row' ) {
                detail.style.display = 'none';
                if ( icon ) icon.style.transform = 'rotate(0deg)';
                return;
            }

            // Close others
            document.querySelectorAll( '.pw-ov-detail' ).forEach( function( d ) {
                d.style.display = 'none';
            } );
            document.querySelectorAll( '.pw-ov-toggle' ).forEach( function( i ) {
                i.style.transform = 'rotate(0deg)';
            } );

            detail.style.display = 'table-row';
            if ( icon ) icon.style.transform = 'rotate(90deg)';

            if ( !loadedGraphs[ id ] ) {
                loadedGraphs[ id ] = true;
                pwOvLoadGraph(
                    id,
                    row.getAttribute( 'data-traffic-url' ),
                    row.getAttribute( 'data-side' ),
                    content
                );
            }
        } );
    } );

    function pwOvLoadGraph( circuitId, trafficUrl, side, container ) {
        var cid = 'pw-ov-graph-' + circuitId;

        container.innerHTML =
            '<div id="' + cid + '" class="px-3 py-2">' +
                '<div class="d-flex align-items-center justify-content-between mb-2">' +
                    '<h6 class="mb-0 text-muted">Your End <small id="' + cid + '-label" class="text-monospace"></small></h6>' +
                    '<div id="' + cid + '-period" class="btn-group btn-group-sm">' +
                        '<button class="btn btn-outline-secondary" data-period="hour">Hour</button>' +
                        '<button class="btn btn-outline-secondary active" data-period="day">Day</button>' +
                        '<button class="btn btn-outline-secondary" data-period="week">Week</button>' +
                        '<button class="btn btn-outline-secondary" data-period="month">Month</button>' +
                        '<button class="btn btn-outline-secondary" data-period="year">Year</button>' +
                    '</div>' +
                '</div>' +
                '<div id="' + cid + '-chart" style="width:100%;min-width:0;"></div>' +
                '<div id="' + cid + '-stats"></div>' +
                '<div id="' + cid + '-loading" class="text-center text-muted py-3">' +
                    '<i class="fa fa-spinner fa-spin"></i> Loading traffic data...' +
                '</div>' +
                '<div id="' + cid + '-empty" class="text-muted small py-2" style="display:none;">No traffic data available.</div>' +
                '<div id="' + cid + '-error" class="alert alert-warning small" style="display:none;"></div>' +
            '</div>';

        pwOvInitChart( cid, trafficUrl, side );
    }

    function pwOvInitChart( cid, trafficUrl, side ) {
        var rxStroke = '#22c55e', rxFill = 'rgba(34,197,94,0.25)';
        var txStroke = '#3b82f6', txFill = 'rgba(59,130,246,0.25)';
        var currentChart = null;

        function fmtSI( val, suffix ) {
            if ( val == null || isNaN( val ) ) return '\u2014';
            var neg = val < 0 ? '-' : '', abs = Math.abs( val );
            if ( abs >= 1e12 ) return neg + ( abs / 1e12 ).toFixed(1) + ' T' + suffix;
            if ( abs >= 1e9 )  return neg + ( abs / 1e9 ).toFixed(1)  + ' G' + suffix;
            if ( abs >= 1e6 )  return neg + ( abs / 1e6 ).toFixed(1)  + ' M' + suffix;
            if ( abs >= 1e3 )  return neg + ( abs / 1e3 ).toFixed(1)  + ' K' + suffix;
            if ( abs > 0 )     return neg + abs.toFixed(0) + ' ' + suffix;
            return '0';
        }

        function tooltipPlugin() {
            var tt = document.createElement( 'div' );
            tt.style.cssText = 'position:absolute;pointer-events:none;padding:6px 10px;border-radius:4px;font-size:12px;background:rgba(30,30,30,0.9);color:#fff;z-index:100;display:none;white-space:nowrap;';
            return {
                hooks: {
                    init: function(u) { u.over.appendChild( tt ); },
                    setCursor: function(u) {
                        var idx = u.cursor.idx;
                        if ( idx == null ) { tt.style.display = 'none'; return; }
                        var ts = u.data[0][idx], rx = u.data[1][idx], tx = u.data[2][idx];
                        var d = new Date( ts * 1000 );
                        tt.innerHTML =
                            '<strong>' + d.toLocaleDateString() + ' ' + d.toLocaleTimeString() + '</strong><br>' +
                            '<span style="color:' + rxStroke + '">\u25B2 RX:</span> ' + fmtSI( rx, 'bps' ) + '<br>' +
                            '<span style="color:' + txStroke + '">\u25BC TX:</span> ' + fmtSI( tx, 'bps' );
                        var left = u.cursor.left + 10;
                        if ( left + 180 > u.over.clientWidth ) left = u.cursor.left - 180;
                        tt.style.left = left + 'px';
                        tt.style.top  = Math.max( 0, u.cursor.top - 40 ) + 'px';
                        tt.style.display = 'block';
                    }
                }
            };
        }

        function calcStats( arr ) {
            if ( !arr || !arr.length ) return { max: 0, avg: 0, cur: 0 };
            var max = 0, sum = 0;
            for ( var i = 0; i < arr.length; i++ ) { var v = arr[i] || 0; if ( v > max ) max = v; sum += v; }
            return { max: max, avg: sum / arr.length, cur: arr[ arr.length - 1 ] || 0 };
        }

        function renderStats( rxArr, txArr ) {
            var el = document.getElementById( cid + '-stats' );
            if ( !el ) return;
            var rxS = calcStats( rxArr ), txS = calcStats( txArr );
            el.innerHTML =
                '<table class="table table-sm table-borderless mt-1 mb-0" style="font-size:0.85rem;max-width:500px;">' +
                '<thead><tr><th></th><th class="text-right">Max</th><th class="text-right">Average</th><th class="text-right">Current</th></tr></thead>' +
                '<tbody>' +
                '<tr><td><span style="color:' + rxStroke + ';font-weight:bold;">RX (In)</span></td>' +
                '<td class="text-right">' + fmtSI( rxS.max, 'bps' ) + '</td>' +
                '<td class="text-right">' + fmtSI( rxS.avg, 'bps' ) + '</td>' +
                '<td class="text-right">' + fmtSI( rxS.cur, 'bps' ) + '</td></tr>' +
                '<tr><td><span style="color:' + txStroke + ';font-weight:bold;">TX (Out)</span></td>' +
                '<td class="text-right">' + fmtSI( txS.max, 'bps' ) + '</td>' +
                '<td class="text-right">' + fmtSI( txS.avg, 'bps' ) + '</td>' +
                '<td class="text-right">' + fmtSI( txS.cur, 'bps' ) + '</td></tr>' +
                '</tbody></table>';
        }

        function renderChart( timestamps, rx, tx ) {
            var el = document.getElementById( cid + '-chart' );
            if ( !el || typeof uPlot === 'undefined' ) return null;
            var w = el.clientWidth || el.parentElement.clientWidth || 600;
            var opts = {
                width: w, height: 220,
                plugins: [ tooltipPlugin() ],
                cursor: { drag: { x: true, y: false } },
                legend: { show: false },
                scales: { x: { time: true }, y: { range: function( u, mn, mx ) { return [ 0, ( mx || 1 ) * 1.15 ]; } } },
                axes: [
                    { stroke: '#888', grid: { stroke: 'rgba(0,0,0,0.07)', width: 1 }, ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 } },
                    { stroke: '#888', grid: { stroke: 'rgba(0,0,0,0.07)', width: 1 }, ticks: { stroke: 'rgba(0,0,0,0.07)', width: 1 }, size: 80,
                      values: function( u, splits ) { return splits.map( function(v) { return fmtSI( v, 'bps' ); } ); } }
                ],
                series: [
                    {},
                    { label: 'RX (In)',  stroke: rxStroke, fill: rxFill, width: 1.5 },
                    { label: 'TX (Out)', stroke: txStroke, fill: txFill, width: 1.5 }
                ]
            };
            el.innerHTML = '';
            return new uPlot( opts, [ timestamps, rx, tx ], el );
        }

        function fetchAndRender( period ) {
            var loadingEl = document.getElementById( cid + '-loading' );
            var errorEl   = document.getElementById( cid + '-error' );
            var emptyEl   = document.getElementById( cid + '-empty' );

            loadingEl.style.display = 'block';
            errorEl.style.display   = 'none';
            emptyEl.style.display   = 'none';

            fetch( trafficUrl + '?period=' + encodeURIComponent( period ) )
                .then( function(r) { if ( !r.ok ) throw new Error( 'HTTP ' + r.status ); return r.json(); } )
                .then( function( data ) {
                    loadingEl.style.display = 'none';

                    // Handle both customer endpoint (my_end) and admin endpoint (a_end/z_end)
                    var end = data.my_end || ( side === 'requester' ? data.a_end : data.z_end ) || {};

                    var labelEl = document.getElementById( cid + '-label' );
                    if ( labelEl && end.label ) labelEl.textContent = '(' + end.label + ')';

                    if ( !end.timestamps || !end.timestamps.length ) {
                        document.getElementById( cid + '-chart' ).innerHTML = '';
                        document.getElementById( cid + '-stats' ).innerHTML = '';
                        emptyEl.style.display = 'block';
                        if ( currentChart ) { currentChart.destroy(); currentChart = null; }
                        return;
                    }

                    emptyEl.style.display = 'none';
                    if ( currentChart ) { currentChart.destroy(); currentChart = null; }
                    currentChart = renderChart( end.timestamps, end.rx, end.tx );
                    renderStats( end.rx, end.tx );
                } )
                .catch( function( err ) {
                    loadingEl.style.display = 'none';
                    errorEl.textContent = 'Failed to load traffic data: ' + err.message;
                    errorEl.style.display = 'block';
                } );
        }

        // Period buttons (vanilla JS)
        document.querySelectorAll( '#' + cid + '-period .btn' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function( e ) {
                e.stopPropagation();
                document.querySelectorAll( '#' + cid + '-period .btn' ).forEach( function( b ) {
                    b.classList.remove( 'active' );
                } );
                btn.classList.add( 'active' );
                fetchAndRender( btn.getAttribute( 'data-period' ) );
            } );
        } );

        // Load uPlot then fetch
        function init() { fetchAndRender( 'day' ); }

        if ( typeof uPlot === 'undefined' ) {
            if ( !document.querySelector( 'link[href*="uPlot"]' ) ) {
                var link = document.createElement( 'link' );
                link.rel = 'stylesheet';
                link.href = '/vendor/uplot/uPlot.min.css';
                document.head.appendChild( link );
            }
            window._uplotQueue = window._uplotQueue || [];
            window._uplotQueue.push( init );
            if ( !window._uplotLoading ) {
                window._uplotLoading = true;
                var s = document.createElement( 'script' );
                s.src = '/vendor/uplot/uPlot.min.js';
                s.onload = function() {
                    window._uplotQueue.forEach( function(fn) { fn(); } );
                    window._uplotQueue = [];
                };
                document.head.appendChild( s );
            }
        } else {
            init();
        }
    }
} );
</script>
<?php endif; ?>
