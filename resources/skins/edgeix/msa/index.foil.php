<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

// Snapshot template vars to locals up front (Foil $t-> access is unreliable
// for generic names later in a template — see project notes).
$msaCust     = $t->msaCust;
$msaSigned   = $t->msaSigned;
$msaIsCustom = $t->msaIsCustom;
$msaDocument = $t->msaDocument;
$msaSignedBy = $t->msaSignedBy;
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Master Services Agreement
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-8 mx-auto">

            <?= $t->alerts() ?>

            <?php if( $msaSigned ): ?>

                <div class="card mb-4 border-success">
                    <div class="card-body text-center py-5">
                        <div class="tw-text-green-500 tw-text-5xl tw-mb-3">
                            <i class="fa fa-check-circle"></i>
                        </div>
                        <h3 class="mb-3">Your MSA is in place</h3>
                        <p class="tw-text-gray-700 tw-max-w-lg tw-mx-auto">
                            <?php if( $msaIsCustom ): ?>
                                A negotiated Master Services Agreement is on record for
                                <strong><?= $t->ee( $msaCust->name ) ?></strong>.
                            <?php else: ?>
                                The EdgeIX Master Services Agreement is on record for
                                <strong><?= $t->ee( $msaCust->name ) ?></strong>.
                            <?php endif; ?>
                            <?php if( $msaCust->msa_signed_at ): ?>
                                Executed on <strong><?= $msaCust->msa_signed_at->format( 'j M Y' ) ?></strong><?php
                                    if( $msaSignedBy ): ?> by <strong><?= $t->ee( $msaSignedBy->name ) ?></strong><?php endif;
                                ?>.
                            <?php endif; ?>
                        </p>
                        <?php if( $msaDocument ): ?>
                            <a class="btn btn-white" href="<?= route( 'docstore-c-file@download', [ 'cust' => $msaCust->id, 'file' => $msaDocument->id ] ) ?>">
                                <i class="fa fa-download"></i> Download signed MSA
                            </a>
                        <?php endif; ?>
                        <div class="tw-mt-4">
                            <a class="btn btn-primary" href="<?= route( 'order@index' ) ?>">
                                Continue to ordering <i class="fa fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

            <?php elseif( $msaIsCustom ): ?>

                <div class="card mb-4 border-info">
                    <div class="card-body text-center py-5">
                        <div class="tw-text-blue-500 tw-text-5xl tw-mb-3">
                            <i class="fa fa-file-signature"></i>
                        </div>
                        <h3 class="mb-3">Your agreement is being finalised</h3>
                        <p class="tw-text-gray-700 tw-max-w-lg tw-mx-auto">
                            A custom Master Services Agreement is in progress between
                            <strong><?= $t->ee( $msaCust->name ) ?></strong> and EdgeIX.
                            Ordering will be enabled as soon as it's executed. If you have
                            any questions in the meantime, contact
                            <a href="mailto:sales@edgeix.net"><strong>sales@edgeix.net</strong></a>.
                        </p>
                    </div>
                </div>

            <?php else: ?>

                <div class="card mb-4 border-warning">
                    <div class="card-body text-center py-5">
                        <div class="tw-text-yellow-500 tw-text-5xl tw-mb-3">
                            <i class="fa fa-file-contract"></i>
                        </div>
                        <h3 class="mb-3">An MSA is required before ordering</h3>
                        <p class="tw-text-gray-700 tw-max-w-lg tw-mx-auto">
                            Before <strong><?= $t->ee( $msaCust->name ) ?></strong> can order
                            services, the EdgeIX Master Services Agreement needs to be executed.
                            Online e-signature is coming soon — in the meantime, email
                            <a href="mailto:sales@edgeix.net"><strong>sales@edgeix.net</strong></a>
                            and our team will send the agreement for signature. Once it's
                            executed, ordering is enabled straight away.
                        </p>
                        <p class="tw-text-sm tw-text-gray-500 tw-max-w-lg tw-mx-auto">
                            Already signed an MSA with us on paper? Let us know at the address
                            above and we'll link it to your account.
                        </p>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
<?php $this->append() ?>
