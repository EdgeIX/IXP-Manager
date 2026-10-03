<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

use IXP\Models\PortOrder;

$o = $t->orderOrder;

$stateLabel = [
    PortOrder::STATE_SUBMITTED         => [ 'badge-warning', 'Submitted — awaiting approval' ],
    PortOrder::STATE_APPROVED          => [ 'badge-info',    'Approved — provisioning in progress' ],
    PortOrder::STATE_PROVISIONED       => [ 'badge-info',    'Provisioned' ],
    PortOrder::STATE_AWAITING_XCONNECT => [ 'badge-primary', 'Awaiting your cross-connect' ],
    PortOrder::STATE_ACTIVE            => [ 'badge-success', 'Active' ],
    PortOrder::STATE_CANCELLED         => [ 'badge-secondary', 'Cancelled' ],
    PortOrder::STATE_EXPIRED           => [ 'badge-secondary', 'Expired' ],
];
[ $badge, $label ] = $stateLabel[ $o->state ] ?? [ 'badge-secondary', $o->state ];
?>

<?php $this->section( 'page-header-preamble' ) ?>
    <a href="<?= route( 'order@index' ) ?>">Orders</a> / #<?= (int)$o->id ?>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-8 mx-auto">

            <?= $t->alerts() ?>

            <div class="card mb-4">
                <div class="card-header">
                    Order #<?= (int)$o->id ?>
                    <span class="badge <?= $badge ?> tw-ml-2"><?= $t->ee( $label ) ?></span>
                </div>
                <div class="card-body">

                    <?php if( $o->isOpen() && $o->macMissing() ): ?>
                        <div class="alert alert-danger">
                            <strong>MAC address required:</strong> your port cannot be provisioned to live
                            service until you provide a MAC address — contact
                            <a href="mailto:sales@edgeix.net">sales@edgeix.net</a> with your MAC
                            (max 2 per MSA Schedule A).
                        </div>
                    <?php endif; ?>

                    <table class="table table-sm mb-0">
                        <tr><td class="tw-w-48"><strong>Placed</strong></td>
                            <td><?= $t->ee( $o->created_at?->format( 'j M Y H:i' ) ) ?></td></tr>
                        <tr><td><strong>Order</strong></td>
                            <td><?= (int)$o->quantity ?> x <?= $t->ee( $o->portType?->name ?? '?' ) ?><?= $o->quantity > 1 ? ' — LACP LAG' : '' ?></td></tr>
                        <tr><td><strong>Location</strong></td>
                            <td><?= $t->ee( $o->location?->name ?? '?' ) ?></td></tr>
                        <tr><td><strong>Tagged</strong></td>
                            <td><?= $o->tagged ? 'Yes — VLAN ' . (int)$o->vlan_tag : 'No (untagged)' ?></td></tr>
                        <tr><td><strong>MAC address(es)</strong></td>
                            <td><?= $o->macMissing() ? '<span class="tw-text-gray-500">not provided yet</span>' : $t->ee( implode( ', ', $o->macs ) ) ?></td></tr>
                        <tr><td><strong>Delivery contact</strong></td>
                            <td><?= $t->ee( $o->delivery_contact ?? '—' ) ?></td></tr>
                        <?php if( $o->po_number ): ?>
                            <tr><td><strong>PO number</strong></td><td><?= $t->ee( $o->po_number ) ?></td></tr>
                        <?php endif; ?>
                        <?php if( $o->preferred_golive ): ?>
                            <tr><td><strong>Preferred go-live</strong></td><td><?= $t->ee( $o->preferred_golive->format( 'j M Y' ) ) ?></td></tr>
                        <?php endif; ?>
                        <?php if( $o->state === PortOrder::STATE_CANCELLED && $o->cancel_reason ): ?>
                            <tr><td><strong>Cancelled</strong></td><td><?= $t->ee( $o->cancel_reason ) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if( $o->isOpen() ): ?>
                <div class="card mb-4">
                    <div class="card-header">What happens next</div>
                    <div class="card-body">
                        <ol class="tw-text-sm tw-text-gray-700 mb-0">
                            <li>Your port has been reserved<?= $o->state === PortOrder::STATE_SUBMITTED ? ' and your order is awaiting approval' : '' ?>.</li>
                            <li>We provision your service and issue a Letter of Authorisation (LOA) for your cross-connect.</li>
                            <li>You order the cross-connect from the data centre using the LOA.</li>
                            <li>Once the cross-connect is in<?= $o->macMissing() ? ' and your MAC address is provided' : '' ?>, we bring your port live.</li>
                        </ol>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
<?php $this->append() ?>
