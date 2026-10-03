<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

use IXP\Models\Customer;

// Snapshot template vars to locals up front (Foil $t-> access is unreliable
// for generic names later in a template — see project notes).
$msaCust = $t->msaCust;
// Foil throws on vars passed as null — try/catch snapshot, not `?? null`.
try { $msaDocument = $t->msaDocument; } catch( \RuntimeException $e ) { $msaDocument = null; }
try { $msaSignedBy = $t->msaSignedBy; } catch( \RuntimeException $e ) { $msaSignedBy = null; }

$msaSigned = $msaCust->msaSigned();
?>

<?php $this->section( 'page-header-preamble' ) ?>
    MSA / <a href="<?= route( 'customer@overview', [ 'cust' => $msaCust->id ] ) ?>"><?= $t->ee( $msaCust->name ) ?></a>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-lg-8 mx-auto">

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
                    Current MSA status
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="tw-w-48"><strong>Status</strong></td>
                            <td>
                                <?php if( $msaSigned ): ?>
                                    <span class="badge badge-success">Signed</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><?= $t->ee( ucfirst( $msaCust->msa_status ) ) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Type</strong></td>
                            <td><?= $msaCust->msa_type === Customer::MSA_TYPE_CUSTOM ? 'Custom (negotiated)' : 'Standard' ?></td>
                        </tr>
                        <?php if( $msaCust->msa_signed_at ): ?>
                            <tr>
                                <td><strong>Executed</strong></td>
                                <td>
                                    <?= $msaCust->msa_signed_at->format( 'j M Y' ) ?>
                                    <?php if( $msaSignedBy ): ?> — signed by <?= $t->ee( $msaSignedBy->name ) ?> (via e-sign)<?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Document</strong></td>
                            <td>
                                <?php if( $msaDocument ): ?>
                                    <a href="<?= route( 'docstore-c-file@download', [ 'cust' => $msaCust->id, 'file' => $msaDocument->id ] ) ?>">
                                        <i class="fa fa-download"></i> <?= $t->ee( $msaDocument->name ) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="tw-text-gray-500">None on file</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if( $msaCust->msa_notes ): ?>
                            <tr>
                                <td><strong>Notes</strong></td>
                                <td><?= nl2br( $t->ee( $msaCust->msa_notes ) ) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    Record an executed MSA
                </div>
                <div class="card-body">
                    <p class="tw-text-sm tw-text-gray-600">
                        Use this to record MSAs executed outside the portal — historic paper
                        agreements and custom negotiated terms. Recording a signed MSA enables
                        ordering for this <?= config( 'ixp_fe.lang.customer.one' ) ?>. The PDF is
                        optional: if you don't have one, record the signatory and where the
                        executed copy is held in the notes.
                    </p>

                    <form method="POST" action="<?= route( 'msa-admin@update', [ 'cust' => $msaCust->id ] ) ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="msa_status">Status</label>
                            <div class="col-sm-9">
                                <select class="form-control" id="msa_status" name="msa_status">
                                    <option value="<?= Customer::MSA_STATUS_SIGNED ?>" <?= old( 'msa_status', $msaCust->msa_status ) === Customer::MSA_STATUS_SIGNED ? 'selected' : '' ?>>Signed — MSA executed, ordering enabled</option>
                                    <option value="<?= Customer::MSA_STATUS_UNSIGNED ?>" <?= old( 'msa_status', $msaCust->msa_status ) === Customer::MSA_STATUS_SIGNED ? '' : 'selected' ?>>Unsigned — no MSA on record, ordering gated</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="msa_type">Type</label>
                            <div class="col-sm-9">
                                <select class="form-control" id="msa_type" name="msa_type">
                                    <option value="<?= Customer::MSA_TYPE_STANDARD ?>" <?= old( 'msa_type', $msaCust->msa_type ) === Customer::MSA_TYPE_CUSTOM ? '' : 'selected' ?>>Standard EdgeIX MSA</option>
                                    <option value="<?= Customer::MSA_TYPE_CUSTOM ?>" <?= old( 'msa_type', $msaCust->msa_type ) === Customer::MSA_TYPE_CUSTOM ? 'selected' : '' ?>>Custom (negotiated terms)</option>
                                </select>
                                <small class="form-text text-muted">
                                    Custom MSAs are exempt from standard-terms version re-consent. When a
                                    custom MSA is in negotiation, set type Custom + status Unsigned — the
                                    customer sees "agreement being finalised" instead of the sign-up flow.
                                </small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="msa_signed_at">Date executed</label>
                            <div class="col-sm-9">
                                <input type="date" class="form-control" id="msa_signed_at" name="msa_signed_at"
                                       value="<?= old( 'msa_signed_at', $msaCust->msa_signed_at?->format( 'Y-m-d' ) ) ?>">
                                <small class="form-text text-muted">Required when recording as signed.</small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="msa_document">Executed PDF</label>
                            <div class="col-sm-9">
                                <input type="file" class="form-control-file" id="msa_document" name="msa_document" accept="application/pdf">
                                <small class="form-text text-muted">
                                    Optional. Stored in the <?= config( 'ixp_fe.lang.customer.one' ) ?>'s docstore
                                    under <em>Agreements</em>, visible to their admin users.
                                    <?php if( $msaDocument ): ?>Uploading a new file replaces the link to the current one.<?php endif; ?>
                                </small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" for="msa_notes">Notes</label>
                            <div class="col-sm-9">
                                <textarea class="form-control" id="msa_notes" name="msa_notes" rows="3"
                                          placeholder="Signatory, agreement date context, where the executed copy is held…"><?= $t->ee( old( 'msa_notes', $msaCust->msa_notes ) ?? '' ) ?></textarea>
                                <small class="form-text text-muted">Required when recording as signed without a PDF.</small>
                            </div>
                        </div>

                        <div class="form-group row mb-0">
                            <div class="col-sm-9 offset-sm-3">
                                <button type="submit" class="btn btn-primary">Save MSA record</button>
                                <a class="btn btn-white" href="<?= route( 'customer@overview', [ 'cust' => $msaCust->id ] ) ?>">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
<?php $this->append() ?>
