<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' );
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Account created — check your email
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">
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
                <div class="tw-bg-white tw-shadow-md tw-rounded-sm tw-px-8 tw-pt-8 tw-pb-8 tw-mb-6 tw-text-center">

                    <div class="tw-text-green-500 tw-text-5xl tw-mb-4">
                        <i class="fa fa-check-circle"></i>
                    </div>

                    <h4 class="tw-mb-3">Your account is ready</h4>

                    <p class="tw-text-sm tw-text-gray-700 tw-mb-4">
                        We've sent you an email with a link to set your password.
                        Follow the link and you'll be able to log in and see your dashboard.
                    </p>

                    <p class="tw-text-xs tw-text-gray-500 tw-mb-6">
                        Didn't get the email? Check your spam folder, then
                        <a href="<?= route( 'forgot-password@show-form' ) ?>">request a new link</a>
                        &mdash; the set-password email works exactly like a password reset.
                        Still stuck? Contact
                        <a href="mailto:support@edgeix.net.au">support@edgeix.net.au</a>.
                    </p>

                    <a href="<?= route( 'login@showForm' ) ?>" class="btn btn-primary tw-w-full">
                        Go to login
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php $this->append() ?>
