<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

use IXP\Models\PortOrder;

$orderCust         = $t->orderCust;
$orderAvailability = $t->orderAvailability;
$orderMyOrders     = $t->orderMyOrders;

$stateBadge = [
    PortOrder::STATE_SUBMITTED         => 'badge-warning',
    PortOrder::STATE_APPROVED          => 'badge-info',
    PortOrder::STATE_PROVISIONED       => 'badge-info',
    PortOrder::STATE_AWAITING_XCONNECT => 'badge-primary',
    PortOrder::STATE_ACTIVE            => 'badge-success',
    PortOrder::STATE_CANCELLED         => 'badge-secondary',
    PortOrder::STATE_EXPIRED           => 'badge-secondary',
];
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Order a Port
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-9 mx-auto">

            <?= $t->alerts() ?>

            <?php if( $errors = session( 'errors' ) ): ?>
                <?php if( $errors->any() ): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach( $errors->all() as $error ): ?>
                                <li><?= $t->ee( $error ) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <i class="fa fa-plug tw-text-blue-500"></i> New Peering Port
                </div>
                <div class="card-body">
                    <?php if( empty( $orderAvailability ) ): ?>
                        <p class="tw-text-gray-700 mb-0">
                            No pre-provisioned ports are available for online ordering right now.
                            Email <a href="mailto:sales@edgeix.net"><strong>sales@edgeix.net</strong></a>
                            with your requirements and we'll arrange your port with a short lead time.
                        </p>
                    <?php else: ?>
                        <form method="POST" action="<?= route( 'order@store' ) ?>" id="order-form">
                            <?= csrf_field() ?>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="locationid">Data centre</label>
                                <div class="col-sm-9">
                                    <select class="form-control" id="locationid" name="locationid" required>
                                        <option value="">— select —</option>
                                        <?php foreach( $orderAvailability as $locId => $loc ): ?>
                                            <option value="<?= (int)$locId ?>" <?= (string)old( 'locationid' ) === (string)$locId ? 'selected' : '' ?>>
                                                <?= $t->ee( $loc['name'] ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">
                                        Only locations with ports ready for immediate provisioning are listed —
                                        need somewhere else? <a href="mailto:sales@edgeix.net">sales@edgeix.net</a>.
                                    </small>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="port_type_id">Port type</label>
                                <div class="col-sm-9">
                                    <select class="form-control" id="port_type_id" name="port_type_id" required>
                                        <option value="">— select a data centre first —</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="quantity">Ports</label>
                                <div class="col-sm-9">
                                    <select class="form-control" id="quantity" name="quantity" required>
                                        <option value="1">1</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        2 or more are delivered as an LACP LAG on a single switch.
                                    </small>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="tagged">802.1q tagged</label>
                                <div class="col-sm-9">
                                    <select class="form-control" id="tagged" name="tagged">
                                        <option value="0" <?= old( 'tagged' ) === '0' || old( 'tagged' ) === null ? 'selected' : '' ?>>No — untagged</option>
                                        <option value="1" <?= old( 'tagged' ) === '1' ? 'selected' : '' ?>>Yes — tagged</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row" id="vlan-row" style="display:none">
                                <label class="col-sm-3 col-form-label" for="vlan_tag">VLAN ID</label>
                                <div class="col-sm-9">
                                    <input type="number" min="1" max="4094" class="form-control" id="vlan_tag" name="vlan_tag"
                                           value="<?= $t->ee( (string)( old( 'vlan_tag' ) ?? '' ) ) ?>">
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">MAC address(es)</label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control mb-2" name="macs[]" placeholder="aa:bb:cc:dd:ee:ff (optional — can be provided later)"
                                           value="<?= $t->ee( old( 'macs.0' ) ?? '' ) ?>">
                                    <input type="text" class="form-control" name="macs[]" placeholder="second MAC (optional, max 2 per MSA Schedule A)"
                                           value="<?= $t->ee( old( 'macs.1' ) ?? '' ) ?>">
                                    <small class="form-text text-muted">
                                        Optional now, but your port cannot be provisioned to live service until a
                                        MAC address is provided.
                                    </small>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="delivery_contact">Delivery contact</label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="delivery_contact" name="delivery_contact" required
                                           maxlength="255" placeholder="name + email/phone for delivery coordination"
                                           value="<?= $t->ee( old( 'delivery_contact' ) ?? '' ) ?>">
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="po_number">PO number</label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="po_number" name="po_number" maxlength="64"
                                           placeholder="optional" value="<?= $t->ee( old( 'po_number' ) ?? '' ) ?>">
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label" for="preferred_golive">Preferred go-live</label>
                                <div class="col-sm-9">
                                    <input type="date" class="form-control" id="preferred_golive" name="preferred_golive"
                                           value="<?= $t->ee( old( 'preferred_golive' ) ?? '' ) ?>">
                                    <small class="form-text text-muted">Optional — otherwise as soon as your cross-connect is in.</small>
                                </div>
                            </div>

                            <div class="form-group row mb-0">
                                <div class="col-sm-9 offset-sm-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa fa-check"></i> Place order
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if( count( $orderMyOrders ) ): ?>
                <div class="card mb-4">
                    <div class="card-header">Your orders</div>
                    <div class="card-body">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-dark">
                                <tr><th>#</th><th>Placed</th><th>Order</th><th>Location</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach( $orderMyOrders as $o ): ?>
                                    <tr>
                                        <td><?= (int)$o->id ?></td>
                                        <td><?= $t->ee( $o->created_at?->format( 'j M Y' ) ) ?></td>
                                        <td><?= (int)$o->quantity ?> x <?= $t->ee( $o->portType?->name ?? '?' ) ?><?= $o->quantity > 1 ? ' (LAG)' : '' ?></td>
                                        <td><?= $t->ee( $o->location?->name ?? '?' ) ?></td>
                                        <td>
                                            <span class="badge <?= $stateBadge[ $o->state ] ?? 'badge-secondary' ?>"><?= $t->ee( str_replace( '_', ' ', $o->state ) ) ?></span>
                                            <?php if( $o->isOpen() && $o->macMissing() ): ?>
                                                <span class="badge badge-danger" title="Provisioning cannot complete until you provide a MAC address">MAC needed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><a class="btn btn-white btn-sm" href="<?= route( 'order@view', [ 'order' => $o->id ] ) ?>">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">Coming soon</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <h6><i class="fa fa-link tw-text-green-500"></i> Add to LAG</h6>
                            <p class="tw-text-sm tw-text-gray-600 mb-0">Add capacity to an existing port. Until then: <a href="mailto:sales@edgeix.net">sales@edgeix.net</a>.</p>
                        </div>
                        <div class="col-md-6 mb-2">
                            <h6><i class="fa fa-arrow-up tw-text-purple-500"></i> Upgrade Port</h6>
                            <p class="tw-text-sm tw-text-gray-600 mb-0">Swap an existing port for a higher speed (IP addresses swing across). Until then: <a href="mailto:sales@edgeix.net">sales@edgeix.net</a>.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Cancellations</div>
                <div class="card-body">
                    <p class="tw-text-sm tw-text-gray-600 mb-0">
                        Cancellations are handled via email at <a href="mailto:sales@edgeix.net">sales@edgeix.net</a>
                        and are subject to the billing terms in your Master Service Agreement.
                    </p>
                </div>
            </div>

        </div>
    </div>

    <script>
    (function() {
        var availability = <?= json_encode( $orderAvailability ) ?>;
        var locSel  = document.getElementById( 'locationid' );
        var typeSel = document.getElementById( 'port_type_id' );
        var qtySel  = document.getElementById( 'quantity' );
        var tagged  = document.getElementById( 'tagged' );
        var vlanRow = document.getElementById( 'vlan-row' );

        if( !locSel ) { return; }

        function fillTypes() {
            var loc = availability[ locSel.value ];
            typeSel.innerHTML = '';
            if( !loc ) {
                typeSel.insertAdjacentHTML( 'beforeend', '<option value="">— select a data centre first —</option>' );
                fillQty( 1 );
                return;
            }
            typeSel.insertAdjacentHTML( 'beforeend', '<option value="">— select —</option>' );
            Object.keys( loc.types ).forEach( function( id ) {
                var tp  = loc.types[ id ];
                var opt = document.createElement( 'option' );
                opt.value = id;
                opt.textContent = tp.name + ' (' + tp.speedLabel + ')';
                if( String( <?= json_encode( old( 'port_type_id' ) ) ?> ) === String( id ) ) { opt.selected = true; }
                typeSel.appendChild( opt );
            } );
            fillQty( 1 );
        }

        function fillQty() {
            var loc = availability[ locSel.value ];
            var max = 1;
            if( loc && loc.types[ typeSel.value ] ) {
                max = Math.min( 8, loc.types[ typeSel.value ].maxSameSwitch );
            }
            qtySel.innerHTML = '';
            for( var i = 1; i <= max; i++ ) {
                var opt = document.createElement( 'option' );
                opt.value = i;
                opt.textContent = i === 1 ? '1' : ( i + ' (LACP LAG)' );
                qtySel.appendChild( opt );
            }
        }

        function toggleVlan() {
            vlanRow.style.display = tagged.value === '1' ? '' : 'none';
        }

        locSel.addEventListener( 'change', fillTypes );
        typeSel.addEventListener( 'change', fillQty );
        tagged.addEventListener( 'change', toggleVlan );

        fillTypes();
        toggleVlan();
    })();
    </script>
<?php $this->append() ?>
