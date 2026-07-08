<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );
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

                <form method="POST" action="<?= route( 'signup@store' ) ?>"
                      class="tw-bg-white tw-shadow-md tw-rounded-sm tw-px-8 tw-pt-6 tw-pb-8 tw-mb-6">
                    <?= csrf_field() ?>

                    <h4 class="tw-text-center tw-mb-4">Create your account</h4>

                    <?php if( config( 'auth.peeringdb.enabled' ) ): ?>
                        <div class="tw-text-center tw-mb-4">
                            <a href="<?= route( 'signup@peeringdb.redirect' ) ?>" class="btn btn-outline-primary tw-w-full">
                                <img class="tw-inline tw-mr-2" style="height:18px;vertical-align:-4px;"
                                     src="<?= asset( 'images/pdb-logo-coloured.png' ) ?>">
                                Sign up with PeeringDB
                            </a>
                            <p class="tw-text-xs tw-text-gray-500 tw-mt-2">
                                Recommended — no data entry, and we'll confirm your ASN ownership.
                            </p>
                        </div>
                        <div class="tw-flex tw-items-center tw-my-4">
                            <hr class="tw-flex-grow">
                            <span class="tw-px-3 tw-text-sm tw-text-gray-500">or</span>
                            <hr class="tw-flex-grow">
                        </div>
                    <?php endif; ?>

                    <p class="tw-text-sm tw-text-gray-600 tw-text-center tw-mb-6">
                        We'll use your ASN to pull your peering details from PeeringDB automatically.
                    </p>

                    <div class="tw-mb-4">
                        <label class="control-label" for="first_name">First Name</label>
                        <input name="first_name" id="first_name" type="text" class="form-control"
                               placeholder="Jane" autofocus required
                               value="<?= $t->ee( old( 'first_name' ) ) ?>">
                        <?php foreach( $t->errors->get( 'first_name' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="tw-mb-4">
                        <label class="control-label" for="last_name">Last Name</label>
                        <input name="last_name" id="last_name" type="text" class="form-control"
                               placeholder="Doe" required
                               value="<?= $t->ee( old( 'last_name' ) ) ?>">
                        <?php foreach( $t->errors->get( 'last_name' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="tw-mb-4">
                        <label class="control-label" for="email">Email</label>
                        <input name="email" id="email" type="email" class="form-control"
                               placeholder="jane@example.com" required
                               value="<?= $t->ee( old( 'email' ) ) ?>">
                        <?php foreach( $t->errors->get( 'email' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="tw-mb-4">
                        <label class="control-label" for="username">Username</label>
                        <input name="username" id="username" type="text" class="form-control"
                               placeholder="jane.doe" required minlength="3" maxlength="255"
                               pattern="[a-z0-9\-_\.]{3,255}"
                               value="<?= $t->ee( old( 'username' ) ) ?>">
                        <p class="tw-text-xs tw-text-gray-500 tw-mt-1">
                            Your login handle. Lowercase letters, digits, dots, hyphens and underscores only.
                        </p>
                        <?php foreach( $t->errors->get( 'username' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="tw-mb-4">
                        <label class="control-label" for="asn">ASN</label>
                        <input name="asn" id="asn" type="number" min="1" class="form-control"
                               placeholder="65001" required
                               value="<?= $t->ee( old( 'asn' ) ) ?>">
                        <p class="tw-text-xs tw-text-gray-500 tw-mt-1">
                            Your public autonomous system number. Must be registered on
                            <a href="https://www.peeringdb.com" target="_blank" rel="noopener">PeeringDB</a>.
                        </p>
                        <?php foreach( $t->errors->get( 'asn' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // Privacy Policy URL comes from config (env-driven, editable via /admin/settings).
                    $privacyUrl = config( 'signup.terms.privacy_url' );
                    ?>
                    <div class="tw-mb-6">
                        <label class="tw-block tw-text-grey-dark">
                            <input class="tw-mr-2 tw-leading-tight" type="checkbox" name="consent" id="consent" value="1"
                                <?= old( 'consent' ) ? 'checked' : '' ?>>
                            <span class="tw-text-sm">
                                I agree to the
                                <?php if( $privacyUrl ): ?>
                                    <a href="<?= $t->ee( $privacyUrl ) ?>" target="_blank" rel="noopener">Privacy Policy</a>.
                                <?php else: ?>
                                    Privacy Policy.
                                <?php endif; ?>
                            </span>
                        </label>
                        <?php foreach( $t->errors->get( 'consent' ) as $err ): ?>
                            <p class="tw-text-red-500 tw-text-xs tw-italic tw-mt-1"><?= $t->ee( $err ) ?></p>
                        <?php endforeach; ?>
                    </div>

                    <div class="tw-flex tw-flex-col tw-gap-3">
                        <button type="submit" class="btn btn-primary tw-w-full">Create Account</button>
                    </div>

                    <hr class="tw-my-4">

                    <p class="tw-text-center tw-text-sm">
                        Already have an account?
                        <a href="<?= route( 'login@showForm' ) ?>">Log in</a>
                    </p>
                </form>

            </div>
        </div>
    </div>
<?php $this->append() ?>
