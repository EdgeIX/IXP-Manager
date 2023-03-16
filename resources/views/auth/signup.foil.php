<?php
/** @var Foil\Template\Template $t */
$this->layout( 'layouts/ixpv4' ) ?>

<?php $this->section( 'page-header-preamble' ) ?>
    Signup for an <?= config( "identity.sitename" ) ?> account
<?php $this->append() ?>

<?php $this->section( 'content' ) ?>
    <div class="row">
        <div class="col-12">
            <?= $t->alerts() ?>
            <div class="tw-text-center tw-my-6">
                <?php if( config( "identity.biglogo" ) ) :?>
                    <img class="tw-inline img-fluid" src="<?= config( "identity.biglogo" ) ?>" />
                <?php else: ?>
                    <h2>
                        [Your Logo Here]
                    </h2>
                    <div>
                        Configure <code>IDENTITY_BIGLOGO</code> in <code>.env</code>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="tw-w-full tw-max-w-xlg tw-mx-auto">
                <?= Former::open()->method( 'POST' )
                    ->id('signupform')
                    ->action( route( 'signup@store' ) )
                    ->class( "tw-bg-white tw-shadow-md tw-rounded-sm tw-px-8 tw-pt-6 tw-pb-8 tw-mb-6" )
                    ->rules(array(
                        'abbreviatedName' => 'max:8',
                        'autsys' => 'number',
                        'nocemail' => 'email',
                        'prefixlimit' => 'number',
                        'billingEmail' => 'email',
                        'useremail' => 'email',
                        )
                    );
                ?>
                <div class="tw-mt-16" style="text-align: center">
                <h3>Account / Login Details</h3>
                <hr>
                </div>
                <?= Former::text( 'username' )
                    ->label( 'Account Username' )
                    ->placeholder( "janedoe" )
                    ->required()
                    ->blockHelp( "The username of the account that will administer this company (ie. Login username")
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'useremail' )
                    ->label( 'Account E-mail Address' )
                    ->placeholder( "janedoe@domain.tld" )
                    ->required()
                    ->blockHelp( "The email address of the account that will administer this company")
                    ->autocomplete( "email" );
                ?>

                <?= Former::text( 'firstlast' )
                    ->label( 'First and Last Name' )
                    ->placeholder( "Jane Doe" )
                    ->required()
                    ->blockHelp( "Name of the user account holder")
                    ->autocomplete( "name" );
                ?>

                <div class="tw-mt-16" style="text-align: center">
                <h3>Customer Details</h3>
                <hr>
                </div>
                <?= Former::text( 'name' )
                    ->label( 'Company or Network Name' )
                    ->placeholder( "Acme Internet Access" )
                    ->required()
                    ->blockHelp( "Your company/network name as you'd like it to appear within the Management Portal and to other members")
                    ->autocomplete( "organization" );
                ?>

                <?= Former::text( 'abbreviatedName' )
                    ->label( 'Abbreviated Name' )
                    ->placeholder( "AIA" )
                    ->blockHelp( "The Abbreviated Name is a shorter version of the name that is used in space constrained areas such as graph labels." )
                    ->required()
                    ->autocomplete( "off" );
                ?>                

                <?= Former::url( 'corpwww' )
                    ->label( 'Corporate Website' )
                    ->placeholder( 'https://www.example.com/' )
                    ->autocomplete( "url" );
                ?>

                <div class="tw-mt-16" style="text-align: center">
                <h3>Network Details</h3>
                <hr>
                </div>
                <?= Former::text( 'autsys' )
                    ->label( 'Autonomous System Number' )
                    ->placeholder( "123456" )
                    ->required()
                    ->blockHelp( "Your company/network name as you'd like it to appear within the Management Portal and to other members")
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'maxprefixes' )
                    ->label( 'Prefix Limit' )
                    ->placeholder( "1000" )
                    ->blockHelp( "The maximum number of prefixes the IX should accept from your network")
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'peeringmacro' )
                    ->label( 'Peering Macro IPv4' )
                    ->placeholder( "AS:ASSETNAME" )
                    ->blockHelp( "The AS-SET or ROUTE-SET object that identifies your valid IPv4 route objects")
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'peeringmacrov6' )
                    ->label( 'Peering Macro IPv6' )
                    ->placeholder( "AS:ASSETNAME" )
                    ->blockHelp( "The AS-SET or ROUTE-SET object that identifies your valid IPv6 route objects")
                    ->autocomplete( "off" );
                ?>

                <?= Former::select( 'peeringpolicy' )
                    ->label( 'Peering Policy' )
                    ->fromQuery( \IXP\Models\Customer::$PEERING_POLICIES )
                    ->placeholder( 'Choose Peering Policy' )
                    ->addClass( 'chzn-select' )
                    ->blockHelp( "Peering Policy as displayed to other members")
                    ->autocomplete( "off" );
                ?>

                <?= Former::select( 'MD5Support' )
                    ->label( 'MD5 Support' )
                    ->fromQuery( \IXP\Models\Customer::$MD5_SUPPORT )
                    ->placeholder( 'Choose MD5 Support' )
                    ->addClass( 'chzn-select' )
                    ->blockHelp( "Select if MD5 Authentication Support is desired for BGP Sessions")
                    ->autocomplete( "off" );
                ?>

                <div class="tw-mt-16" style="text-align: center">
                <h3>NOC Details</h3>
                <hr>
                </div>
                <?= Former::text( 'nocphone' )
                    ->label( 'NOC Phone Number (24x7)' )
                    ->placeholder( "+61 8 xxxx xxxx" )
                    ->required()
                    ->blockHelp( "Phone Number for contact in-case of network emergency")
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'nocemail' )
                    ->label( 'NOC Email Address' )
                    ->placeholder( "noc@domain.tld" )
                    ->required()
                    ->autocomplete( "off" );
                ?>

                <?= Former::select( 'nochours' )
                    ->label( 'NOC Operating Hours' )
                    ->fromQuery( \IXP\Models\Customer::$NOC_HOURS )
                    ->placeholder( 'Choose Operating Hours' )
                    ->addClass( 'chzn-select' )
                    ->autocomplete( "off" );
                ?>

                <div class="tw-mt-16" style="text-align: center">
                <h3>Billing Details</h3>
                <hr>
                </div>
                <?= Former::text( 'billingContactName' )
                    ->label( 'Billing Contact Name' )
                    ->placeholder( "" )
                    ->required();
                ?>

                <?= Former::text( 'billingAddress1' )
                    ->label( 'Billing Address' )
                    ->placeholder( "" )
                    ->required()
                    ->autocomplete( "address-line1" );
                ?>

                <?= Former::text( 'billingAddress2' )
                    ->placeholder( "" )
                    ->label( ' ' )
                    ->autocomplete( "address-line2" );
                ?>

                <?= Former::text( 'billingAddress3' )
                    ->placeholder( "" )
                    ->label( ' ' )
                    ->autocomplete( "address-line3" );
                ?>

                <?= Former::text( 'billingTownCity' )
                    ->label( 'City' )
                    ->placeholder( "" )
                    ->required()
                    ->autocomplete( "address-level2" );
                ?>

                <?= Former::text( 'billingPostcode' )
                    ->label( 'Postcode' )
                    ->placeholder( "" )
                    ->required()
                    ->autocomplete( "postal-code" );
                ?>

                <?= Former::select( 'billingCountry' )
                    ->label( 'Country' )
                    ->fromQuery( $t->countries, 'name', 'iso_3166_2' )
                    ->placeholder( 'Choose a country' )
                    ->addClass( 'chzn-select' )
                    ->blockHelp( '' )
                    ->required()
                    ->autocomplete( "country" );
                ?>

                <?= Former::text( 'billingEmail' )
                    ->label( 'Billing Email' )
                    ->placeholder( "" )
                    ->required()
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'billingTelephone' )
                    ->label( 'Billing Telephone' )
                    ->placeholder( "" )
                    ->required()
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'vatNumber' )
                    ->placeholder( "" )
                    ->label( 'GST / VAT Number' )
                    ->autocomplete( "off" );
                ?>

                <div class="tw-mt-16" style="text-align: center">
                <h3>Business Details</h3>
                <hr>
                </div>
                <?= Former::text( 'registeredName' )
                    ->label( 'Registered Business Name' )
                    ->placeholder( "" )
                    ->blockHelp( 'Official Business Name as per tax / company registration details' )
                    ->required()
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'companyNumber' )
                    ->label( 'Company Number' )
                    ->placeholder( "" )
                    ->blockHelp( 'ACN / GST or VAT Identification Number' )
                    ->required()
                    ->autocomplete( "off" );
                ?>

                <?= Former::text( 'address1' )
                    ->label( 'Registered Address' )
                    ->required()
                    ->autocomplete( "address-line1" );
                ?>

                <?= Former::text( 'address2' )
                    ->label( ' ' )
                    ->autocomplete( "address-line2" );
                ?>

                <?= Former::text( 'address3' )
                    ->label( ' ' )
                    ->autocomplete( "address-line3" );
                ?>

                <?= Former::text( 'townCity' )
                    ->label( 'City' )
                    ->required()
                    ->autocomplete( "address-level2" );
                ?>

                <?= Former::text( 'postcode' )
                    ->label( 'Postcode' )
                    ->required()
                    ->autocomplete( "postal-code" );
                ?>

                <?= Former::select( 'country' )
                    ->label( 'Country' )
                    ->fromQuery( $t->countries, 'name', 'iso_3166_2' )
                    ->placeholder( 'Choose a country' )
                    ->addClass( 'chzn-select' )
                    ->blockHelp( '' )
                    ->required()
                    ->autocomplete( "country" );
                ?>

                <?= Former::checkbox( 'msa' )
                    ->label( 'Agreement' )
                    ->append('<a href="'.env('SIGNUP_MSA').'">Master Services Agreement</a>')
                    ->inlineHelp( 'I have read, understand and agree to all conditions and obligations under the agreement linked above')
                    ->required(); 
                ?>
                <div class="form-group row required">
                    <label for="captcha" class="control-label col-lg-2 col-sm-4">CAPTCHA<sup>*</sup></label>
                    <div class="col-lg-10 col-sm-8">
                        <?= NoCaptcha::display() ?>
                    </div>
                </div>

                <?=Former::actions( Former::primary_submit( 'Signup' )->class( "mb-2 mb-sm-0" ),
                    Former::success_button( 'Help' )->id( 'help-btn' )->class( "mb-2 mb-sm-0")
                );?>

                <?= Former::close() ?>

            </div>
        </div>
    </div>
<?php
$this->append()

/*
Values to default (not in the form):
$defaults=[
    //cust table
    created => date('Y-m-d'),
    datejoin => date('Y-m-d'),
    dateleave => null,
    status => 2,
    type => 1,
    activepeeringmatrix => 0,
    creator => 'signup-form',
    irrdb => 14,
    //company_billing_detail table
    vatRate => '10%',
    invoiceMethod => 'EMAIL',
    invoiceEmail => billingEmail
]
    */
?>

