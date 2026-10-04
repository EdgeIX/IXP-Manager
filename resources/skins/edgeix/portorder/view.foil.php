<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

use IXP\Models\PortOrder;

$o = $t->poOrder;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <a href="<?= route( 'port-order-admin@index' ) ?>">Port Orders</a> / #<?= (int)$o->id ?>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-9 mx-auto">

            <?= $t->alerts() ?>

            <?php if( $errors = session( 'errors' ) ): ?>
                <?php if( $errors->any() ): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0"><?php foreach( $errors->all() as $error ): ?><li><?= $t->ee( $error ) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    Order #<?= (int)$o->id ?> — <?= $t->ee( $o->customer?->name ?? '?' ) ?>
                    <span class="badge badge-info tw-ml-2"><?= $t->ee( str_replace( '_', ' ', $o->state ) ) ?></span>
                    <?php if( $o->macMissing() && $o->isOpen() ): ?>
                        <span class="badge badge-danger">MAC needed</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><td class="tw-w-48"><strong>Customer</strong></td>
                            <td><a href="<?= route( 'customer@overview', [ 'cust' => $o->custid ] ) ?>"><?= $t->ee( $o->customer?->name ?? '?' ) ?></a></td></tr>
                        <tr><td><strong>Placed</strong></td>
                            <td><?= $t->ee( $o->created_at?->format( 'j M Y H:i' ) ) ?> by <?= $t->ee( $o->user?->username ?? 'n/a' ) ?></td></tr>
                        <tr><td><strong>Order</strong></td>
                            <td>
                                <?= (int)$o->quantity ?> x <?= $t->ee( $o->portType?->name ?? '?' ) ?><?= $o->quantity > 1 ? ' — LACP LAG' : '' ?> at <?= $t->ee( $o->location?->name ?? '?' ) ?>
                                <?php if( $o->customer_locationid ): ?>
                                    <span class="badge badge-info" title="Customer is at a served-via site — LOA/demarc is <?= $t->ee( $o->location?->name ?? '?' ) ?>">customer at <?= $t->ee( $o->customerLocation?->name ?? '?' ) ?></span>
                                <?php endif; ?>
                            </td></tr>
                        <tr><td><strong>Tagged / VLAN</strong></td>
                            <td><?= $o->tagged ? 'Tagged, VLAN ' . (int)$o->vlan_tag : 'Untagged' ?></td></tr>
                        <tr><td><strong>MACs</strong></td>
                            <td><?= $o->macMissing() ? 'not provided' : $t->ee( implode( ', ', $o->macs ) ) ?></td></tr>
                        <tr><td><strong>Delivery contact</strong></td><td><?= $t->ee( $o->delivery_contact ?? '—' ) ?></td></tr>
                        <tr><td><strong>PO</strong></td><td><?= $t->ee( $o->po_number ?? '—' ) ?></td></tr>
                        <tr><td><strong>Preferred go-live</strong></td><td><?= $o->preferred_golive ? $t->ee( $o->preferred_golive->format( 'j M Y' ) ) : '—' ?></td></tr>
                        <tr><td><strong>Timeline</strong></td>
                            <td class="tw-text-sm">
                                placed <?= $t->ee( (string)$o->created_at ) ?>
                                <?php if( $o->approved_at ): ?><br>approved <?= $t->ee( (string)$o->approved_at ) ?> (<?= $o->approved_by ? 'admin' : 'auto-policy' ?>)<?php endif; ?>
                                <?php if( $o->state === PortOrder::STATE_SUBMITTED && $o->reserved_until ): ?><br>hold expires <?= $t->ee( (string)$o->reserved_until ) ?><?php endif; ?>
                                <?php if( $o->cancelled_at ): ?><br><?= $t->ee( $o->state ) ?> <?= $t->ee( (string)$o->cancelled_at ) ?><?= $o->cancel_reason ? ' — ' . $t->ee( $o->cancel_reason ) : '' ?><?php endif; ?>
                            </td></tr>
                    </table>

                    <h6>Reserved port(s)</h6>
                    <table class="table table-sm table-striped">
                        <thead class="thead-dark"><tr><th>Switch</th><th>Port</th><th>Patch panel</th></tr></thead>
                        <tbody>
                            <?php foreach( $o->portOrderPorts as $pp ): ?>
                                <tr>
                                    <td><?= $t->ee( $pp->switchPort?->switcher?->name ?? '?' ) ?></td>
                                    <td><?= $t->ee( $pp->switchPort?->ifName ?? '?' ) ?></td>
                                    <td>
                                        <?php if( $pp->patchPanelPort ): ?>
                                            <a href="<?= route( 'patch-panel-port@list-for-patch-panel', [ 'pp' => $pp->patchPanelPort->patch_panel_id ] ) ?>">
                                                <?= $t->ee( $pp->patchPanelPort->patchPanel?->name ?? 'panel' ) ?> #<?= (int)$pp->patchPanelPort->number ?>
                                            </a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if( $o->state === PortOrder::STATE_BACKORDER ): ?>
                        <div class="alert alert-warning">
                            <strong>Backorder — no stock was available at placement.</strong>
                            Arrange the capacity (prewire / structured cabling / new switch), confirm it shows as
                            sellable on <a href="<?= route( 'port-stock@index' ) ?>">Port Stock</a>, then click
                            <em>Reserve ports &amp; approve</em>.
                        </div>
                    <?php endif; ?>

                    <div class="tw-flex tw-gap-2">
                        <?php if( $o->state === PortOrder::STATE_BACKORDER ): ?>
                            <form method="POST" action="<?= route( 'port-order-admin@reserve', [ 'order' => $o->id ] ) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-success">Reserve ports &amp; approve</button>
                            </form>
                        <?php endif; ?>
                        <?php if( $o->state === PortOrder::STATE_SUBMITTED ): ?>
                            <form method="POST" action="<?= route( 'port-order-admin@approve', [ 'order' => $o->id ] ) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-success">Approve</button>
                            </form>
                        <?php endif; ?>
                        <?php if( $o->isOpen() ): ?>
                            <form method="POST" action="<?= route( 'port-order-admin@cancel', [ 'order' => $o->id ] ) ?>" class="form-inline d-inline"
                                  onsubmit="return confirm( 'Cancel order #<?= (int)$o->id ?> and release its port(s)?' );">
                                <?= csrf_field() ?>
                                <input type="text" class="form-control form-control-sm tw-mr-1" name="cancel_reason" placeholder="cancellation reason" required>
                                <button type="submit" class="btn btn-danger btn-sm">Cancel order</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
