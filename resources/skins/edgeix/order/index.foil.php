<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

$cust = $t->cust ?? null;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Order a Port
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-8 mx-auto">

            <?= $t->alerts() ?>

            <div class="card mb-4 border-warning">
                <div class="card-body text-center py-5">
                    <div class="tw-text-yellow-500 tw-text-5xl tw-mb-3">
                        <i class="fa fa-hourglass-half"></i>
                    </div>
                    <h3 class="mb-3">Online ordering is coming soon</h3>
                    <p class="tw-text-gray-700 tw-max-w-lg tw-mx-auto">
                        We're building a self-service order flow into the portal.
                        In the meantime, please email
                        <a href="mailto:sales@edgeix.net.au"><strong>sales@edgeix.net.au</strong></a>
                        with your order details and our team will get you provisioned.
                    </p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">What you'll be able to order</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <h5><i class="fa fa-plug tw-text-blue-500"></i> New Port</h5>
                            <p class="tw-text-sm tw-text-gray-600">
                                A new peering port at an existing EdgeIX location, or at a new
                                location. Choose speed, PoP and DC.
                            </p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <h5><i class="fa fa-link tw-text-green-500"></i> Add to LAG</h5>
                            <p class="tw-text-sm tw-text-gray-600">
                                Add capacity to an existing port. If the port isn't already a LAG,
                                we'll convert it as part of the order.
                            </p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <h5><i class="fa fa-arrow-up tw-text-purple-500"></i> Upgrade Port</h5>
                            <p class="tw-text-sm tw-text-gray-600">
                                Increase the speed of an existing port. We'll swing your IP
                                addresses across as part of the change.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Cancellations</div>
                <div class="card-body">
                    <p class="tw-text-sm tw-text-gray-600 mb-0">
                        Cancellations are handled via email at
                        <a href="mailto:sales@edgeix.net.au">sales@edgeix.net.au</a>
                        and are subject to the billing terms in your Master Service Agreement.
                    </p>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
