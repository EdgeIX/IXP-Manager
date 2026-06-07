<?php
/** @var Foil\Template\Template $t */

/** @var $t ->active */

use IXP\Models\Customer;
use XeroAPI\XeroPHP\Models\Accounting\RepeatingInvoice;

$this->layout( 'layouts/ixpv4' );

$setupMembers = [];
?>

<?php $this->section( 'page-header-preamble' ) ?>
Xero Repeating Invoices
<?php $this->append() ?>

<?php $this->section( 'page-header-postamble' ) ?>
<a class="btn btn-white btn-sm" href="<?= route( 'xero.auth.success' ) ?>" title="Xero Admin">
    <span class="fa fa-arrow-right"></span> Xero Admin
</a>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
<?= $t->alerts() ?>
<p class="text-info">Showing all FULL Customers</p>
<table class="table table-striped">
    <thead>
    <tr>
        <th>Customer</th>
        <th>Customer Type</th>
        <th>Date Joined</th>
        <th>Services</th>
        <th>Needs Billing</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach( $t->bills as $bill ): ?>
        <tr>
            <td>
                <a href="<?= route('xero.repeating.invoices.customer', ['customer_id' => $bill['customer']->id]) ?>">
                    <?= $bill['customer']->name ?></a>
            </td>
            <td><?= $bill['customer']->type() ?></td>
            <td><?= $bill['customer']->datejoin->format('Y-m-d') ?></td>
            <td>
                <table class="table">
                <?php foreach( $bill['services'] as $service ): ?>
                    <?php
                        $isExt   = $service->is_extended ?? false;
                        $billed  = $service->billed_speed ?? $service->speed;
                        $hasCmt  = $isExt && ($service->extended_commit ?? null);
                    ?>
                <tr>
                    <td>
                        <?= $service->vlan_name ?>
                        <?php if( $isExt ): ?>
                            <span class="badge badge-info" title="Extended peering (port-infra differs from VLAN-infra). Billed at commit, not port speed.">Extended</span>
                            <?php if( !$hasCmt ): ?>
                                <span class="badge badge-warning" title="No commit configured — billed at port speed until admin sets one in /pseudowire/admin/extended-peering">no commit</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-right mono">
                        <?= $billed / 1000 ?>gbps
                        <?php if( $isExt && $hasCmt ): ?>
                            <small class="text-muted">(commit; port <?= $service->speed / 1000 ?>g)</small>
                        <?php endif; ?>
                    </td>
                    <td><?= $service->location_name ?></td>
                </tr>
                <?php endforeach; ?>
                </table>
            </td>
            <td>
                <table class="table">
                <?php
                    // Index services-needing-billing by vli_id so we can iterate
                    // the FULL services list on this side and leave blanks where
                    // billing already matches — keeps left/right columns aligned
                    // row-by-row.
                    $needBillIds = [];
                    foreach( $bill['servicesNeedingBilling'] as $nb ) {
                        if( !empty( $nb->vli_id ) ) {
                            $needBillIds[ $nb->vli_id ] = true;
                        }
                    }
                ?>
                <?php foreach( $bill['services'] as $service ): ?>
                    <?php $needs = isset( $needBillIds[ $service->vli_id ?? '' ] ); ?>
                <tr>
                    <?php if( $needs ): ?>
                        <?php
                            $isExt  = $service->is_extended ?? false;
                            $billed = $service->billed_speed ?? $service->speed;
                        ?>
                    <td>
                        <?= $service->vlan_name ?>
                        <?php if( $isExt ): ?>
                            <span class="badge badge-info">Extended</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $billed / 1000 ?>gbps</td>
                    <td><?= $service->location_name ?></td>
                    <?php else: ?>
                    <td>&nbsp;</td>
                    <td></td>
                    <td></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </table>
            </td>
        </tr>
    <?php endforeach; ?>


    </tbody>
</table>
<?php $this->append() ?>


<?php $this->section( 'scripts' ) ?>
<script>
    $(document).ready(function () {


    });
</script>
<?php $this->append() ?>
