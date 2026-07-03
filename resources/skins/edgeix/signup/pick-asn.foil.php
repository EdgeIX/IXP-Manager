<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );

$eligible  = $t->eligible  ?? [];
$infoOnly  = $t->infoOnly  ?? [];
$firstName = $t->firstName ?? '';
$lastName  = $t->lastName  ?? '';
$email     = $t->email     ?? '';

$privacyUrl = config( 'signup.terms.privacy_url' );
$termsUrl   = config( 'signup.terms.terms_url'   );
$aupUrl     = config( 'signup.terms.aup_url' ) ?: route( 'pw@aup' );
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Sign up for <?= config( "identity.sitename" ) ?>
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">
            <?= $t->alerts() ?>
            <div class="tw-text-center tw-my-6">
                <?php if( config( "identity.biglogo" ) ) : ?>
                    <img class="tw-inline img-fluid tw-w-full tw-max-w-sm tw-mx-auto" src="<?= config( "identity.biglogo" ) ?>" />
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="tw-w-full tw-max-w-md tw-mx-auto">

                <form method="POST" action="<?= route( 'signup@peeringdb.confirm' ) ?>"
                      class="tw-bg-white tw-shadow-md tw-rounded-sm tw-px-8 tw-pt-6 tw-pb-8 tw-mb-6">
                    <?= csrf_field() ?>

                    <h4 class="tw-text-center tw-mb-4">Confirm your signup</h4>

                    <p class="tw-text-sm tw-text-gray-700 tw-mb-4">
                        Hi <?= $t->ee( $firstName ) ?>, we found you on PeeringDB
                        <?php if( $email ): ?>as <span class="tw-font-mono"><?= $t->ee( $email ) ?></span><?php endif; ?>.
                    </p>

                    <?php if( count( $eligible ) === 1 ): ?>
                        <?php $only = $eligible[0]; ?>
                        <p class="tw-text-sm tw-mb-4">
                            Sign up as:
                        </p>
                        <div class="tw-mb-4 tw-p-3 tw-border tw-rounded tw-bg-gray-50">
                            <input type="hidden" name="asn" value="<?= (int) $only['asn'] ?>">
                            <div class="tw-font-semibold">AS<?= (int) $only['asn'] ?></div>
                            <div class="tw-text-sm tw-text-gray-600"><?= $t->ee( $only['name'] ) ?></div>
                        </div>
                    <?php else: ?>
                        <p class="tw-text-sm tw-mb-3">
                            You administer multiple networks on PeeringDB. Choose which one you're
                            signing up as:
                        </p>
                        <div class="tw-mb-4">
                            <?php foreach( $eligible as $i => $net ): ?>
                                <label class="tw-block tw-p-3 tw-border tw-rounded tw-mb-2 tw-cursor-pointer hover:tw-bg-gray-50">
                                    <input type="radio" name="asn" value="<?= (int) $net['asn'] ?>"
                                           class="tw-mr-2" <?= $i === 0 ? 'checked' : '' ?>>
                                    <span class="tw-font-semibold">AS<?= (int) $net['asn'] ?></span>
                                    <span class="tw-text-sm tw-text-gray-600"> — <?= $t->ee( $net['name'] ) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if( !empty( $infoOnly ) ): ?>
                        <div class="tw-text-xs tw-text-gray-500 tw-bg-blue-50 tw-border tw-border-blue-100 tw-rounded tw-p-2 tw-mb-4">
                            <strong>Note:</strong> The following networks in your PeeringDB affiliation are already registered:
                            <ul class="tw-list-disc tw-ml-4 tw-mt-1">
                                <?php foreach( $infoOnly as $info ): ?>
                                    <li>
                                        AS<?= (int) $info['asn'] ?> (<?= $t->ee( $info['name'] ) ?>) —
                                        <?php if( $info['oauth_ready'] ): ?>
                                            <a href="<?= route( 'auth:login-peeringdb' ) ?>">log in with PeeringDB</a>
                                        <?php else: ?>
                                            contact your account admin to be added
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="tw-mb-6 tw-mt-4">
                        <label class="tw-block tw-text-grey-dark">
                            <input class="tw-mr-2 tw-leading-tight" type="checkbox" name="consent" id="consent" value="1"
                                <?= old( 'consent' ) ? 'checked' : '' ?>>
                            <span class="tw-text-sm">
                                I agree to the
                                <?php if( $privacyUrl ): ?>
                                    <a href="<?= $t->ee( $privacyUrl ) ?>" target="_blank" rel="noopener">Privacy Policy</a>,
                                <?php else: ?>
                                    Privacy Policy,
                                <?php endif; ?>
                                <?php if( $termsUrl ): ?>
                                    <a href="<?= $t->ee( $termsUrl ) ?>" target="_blank" rel="noopener">Site Terms</a>
                                <?php else: ?>
                                    Site Terms
                                <?php endif; ?>
                                and
                                <a href="<?= $t->ee( $aupUrl ) ?>" target="_blank" rel="noopener">Acceptable Use Policy</a>.
                            </span>
                        </label>
                        <?php foreach( $t->errors->get( 'consent' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary tw-w-full">Create Account</button>

                    <hr class="tw-my-4">

                    <p class="tw-text-center tw-text-xs tw-text-gray-500">
                        <a href="<?= route( 'signup@create' ) ?>">Cancel and go back</a>
                    </p>
                </form>

            </div>
        </div>
    </div>
<?php $this->append() ?>
