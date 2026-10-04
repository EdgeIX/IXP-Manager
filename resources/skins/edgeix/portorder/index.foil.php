<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

use IXP\Models\PortOrder;

$poOrders = $t->poOrders;
$poState  = $t->poState;

$stateBadge = [
    PortOrder::STATE_BACKORDER         => 'badge-danger',
    PortOrder::STATE_SUBMITTED         => 'badge-warning',
    PortOrder::STATE_APPROVED          => 'badge-info',
    PortOrder::STATE_PROVISIONED       => 'badge-info',
    PortOrder::STATE_AWAITING_XCONNECT => 'badge-primary',
    PortOrder::STATE_ACTIVE            => 'badge-success',
    PortOrder::STATE_CANCELLED         => 'badge-secondary',
    PortOrder::STATE_EXPIRED           => 'badge-secondary',
];

$filters = [ 'open', PortOrder::STATE_BACKORDER, PortOrder::STATE_SUBMITTED, PortOrder::STATE_AWAITING_XCONNECT, PortOrder::STATE_ACTIVE, 'all' ];
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Port Orders
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
    <div class="btn-group btn-group-sm" role="group">
        <a class="btn btn-white" href="<?= route( 'port-stock@index' ) ?>"><i class="fa fa-cubes"></i> Port Stock</a>
    </div>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">

            <?= $t->alerts() ?>

            <div class="card">
                <div class="card-header">
                    Order queue
                    <span class="float-right">
                        <?php foreach( $filters as $f ): ?>
                            <a class="btn btn-sm <?= $poState === $f ? 'btn-primary' : 'btn-white' ?>"
                               href="<?= route( 'port-order-admin@index', [ 'state' => $f ] ) ?>"><?= $t->ee( str_replace( '_', ' ', $f ) ) ?></a>
                        <?php endforeach; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if( !count( $poOrders ) ): ?>
                        <p class="tw-text-gray-500 mb-0">No orders in this view.</p>
                    <?php else: ?>
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-dark">
                                <tr><th>#</th><th>Placed</th><th>Customer</th><th>Order</th><th>Location</th><th>State</th><th>Hold until</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach( $poOrders as $o ): ?>
                                    <tr>
                                        <td><?= (int)$o->id ?></td>
                                        <td class="tw-whitespace-nowrap"><?= $t->ee( $o->created_at?->format( 'j M H:i' ) ) ?></td>
                                        <td><a href="<?= route( 'customer@overview', [ 'cust' => $o->custid ] ) ?>"><?= $t->ee( $o->customer?->abbreviatedName ?? $o->customer?->name ?? '?' ) ?></a></td>
                                        <td>
                                            <?= (int)$o->quantity ?> x <?= $t->ee( $o->portType?->name ?? '?' ) ?><?= $o->quantity > 1 ? ' (LAG)' : '' ?>
                                            <?php if( $o->macMissing() && $o->isOpen() ): ?>
                                                <span class="badge badge-danger">MAC needed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $t->ee( $o->location?->name ?? '?' ) ?></td>
                                        <td><span class="badge <?= $stateBadge[ $o->state ] ?? 'badge-secondary' ?>"><?= $t->ee( str_replace( '_', ' ', $o->state ) ) ?></span></td>
                                        <td class="tw-text-xs"><?= $o->state === PortOrder::STATE_SUBMITTED ? $t->ee( (string)$o->reserved_until?->format( 'j M Y' ) ) : '—' ?></td>
                                        <td class="text-right">
                                            <a class="btn btn-white btn-sm" href="<?= route( 'port-order-admin@view', [ 'order' => $o->id ] ) ?>">View</a>
                                            <?php if( $o->state === PortOrder::STATE_SUBMITTED ): ?>
                                                <form method="POST" action="<?= route( 'port-order-admin@approve', [ 'order' => $o->id ] ) ?>" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
