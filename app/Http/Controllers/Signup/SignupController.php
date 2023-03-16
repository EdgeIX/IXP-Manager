<?php

namespace IXP\Http\Controllers\Signup;

use Auth, Countries, Former, Hash, Log, Mail;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\View\View;

use Illuminate\Http\{
    RedirectResponse,
    Request
};

use IXP\Events\User\UserCreated as UserCreatedEvent;
use IXP\Events\User\UserAddedToCustomer as UserAddedToCustomerEvent;

use IXP\Http\Controllers\Controller;

use IXP\Http\Requests\User\{
    CheckEmail      as CheckEmailRequest,
    Delete          as DeleteRequest,
    Store           as StoreUser,
    Update          as UpdateUser,
};

use IXP\Http\Requests\Customer\{
    Store                   as CustomerRequest,
    BillingInformation      as BillingInformationRequest,
    WelcomeEmail            as WelcomeEmailRequest
};

use IXP\Http\Requests\Signup\{
    Store                   as SignupRequest,
};

use IXP\Mail\User\UserCreated as UserCreatedeMailable;

use IXP\Models\{
    CompanyBillingDetail,
    CompanyRegisteredDetail,
    Customer,
    CustomerToUser,
    User
};

use IXP\Utils\View\Alert\{
    Alert,
    Container as AlertContainer
};

class SignupController extends Controller
{

    public function __construct() {
        $this->middleware( 'guest' )->except( 'logout' );
    }

    public function create() {
        return view('auth.signup')->with([
            'countries' => Countries::getList('name'),
        ]);
    }

    public function store( SignupRequest $r ): RedirectResponse 
    {

        $validator = \Validator::make($r->all(), [
            'g-recaptcha-response' => 'required|captcha',
            'msa' => 'accepted',
        ]);

        if($validator->fails()) {
            $failed = $validator->failed();
            if(isset($failed['g-recaptcha-response'])){
                AlertContainer::push( 'Error - Invalid ReCAPTCHA', Alert::DANGER );
            }
            if(isset($failed['msa'])){
                AlertContainer::push( 'Error - Master Services Agreement was not agreed to', Alert::DANGER );
            }
            return redirect( route( 'signup@create' ) );
        }

        $form = $r->all();

        if(
            Customer::where('autsys', $form['autsys'])->exists() || 
            Customer::where('name', $form['name'])->exists() || 
            Customer::where('abbreviatedName', $form['abbreviatedName'])->exists()
        )
        {
            AlertContainer::push( 'Error Processing Signup - Contact Administrator', Alert::DANGER );
            return redirect( route( 'signup@create' ) );
        }

        if(User::where('username', $form['username'])->exists() || User::where('email', $form['useremail'])->exists()){
            AlertContainer::push( 'Error Processing Signup - Contact Administrator', Alert::DANGER );
            return redirect( route( 'signup@create' ) );
        }

        $billing = [
            'billingContactName'    => $form['billingContactName'],
            'billingAddress1'       => $form['billingAddress1'],
            'billingAddress2'       => $form['billingAddress2'],
            'billingAddress3'       => $form['billingAddress3'],
            'billingTownCity'       => $form['billingTownCity'],
            'billingPostcode'       => $form['billingPostcode'],
            'billingCountry'        => $form['billingCountry'],
            'billingEmail'          => $form['billingEmail'],
            'billingTelephone'      => $form['billingTelephone'],
            'vatRate'               => '10%',
            'invoiceMethod'         => 'EMAIL',
            'invoiceEmail'          => $form['billingEmail'],
        ];

        $registration = [
            'registeredName'        => $form['registeredName'],
            'companyNumber'         => $form['companyNumber'],
            'address1'              => $form['address1'],
            'address2'              => $form['address2'],
            'address3'              => $form['address3'],
            'townCity'              => $form['townCity'],
            'postcode'              => $form['postcode'],
            'country'               => $form['country'],
        ];

        $registrationId = CompanyRegisteredDetail::create($registration);
        $billingId = CompanyBillingDetail::create($billing);

        $customer = [
            'name' => $form['name'],
            'abbreviatedName' => $form['abbreviatedName'],
            'corpwww' => $form['corpwww'],
            'autsys' => $form['autsys'],
            'maxprefixes' => $form['maxprefixes'],
            'peeringmacro' => $form['peeringmacro'],
            'peeringmacrov6' => $form['peeringmacrov6'],
            'nocphone' => $form['nocphone'],
            'nocemail' => $form['nocemail'],
            'created' => date('Y-m-d'),
            'datejoin' => date('Y-m-d'),
            'dateleave' => null,
            'status' => 2,
            'type' => 1,
            'activepeeringmatrix' => 0,
            'creator' => env('SIGNUP_NAME'),
            'irrdb' => env('IRRDB_CONFIG_ID'),
            'company_registered_detail_id' => $registrationId->id,
            'company_billing_details_id' => $billingId->id,
        ];

        $customerId = Customer::create($customer);

        $user = new User;

        $user->username = $form['username'];
        $user->email = $form['useremail'];
        $user->password = Hash::make( Str::random(16) );
        $user->name = $form['firstlast'];
        $user->custid = $customerId->id;
        $user->privs = 2;
        $user->creator = env('SIGNUP_NAME');

        $user->save();

        $custToUser = new CustomerToUser;

        $custToUser->customer_id = $customerId->id;
        $custToUser->user_id = $user->id;
        $custToUser->privs = 2;
        $custToUser->extra_attributes = [ "created_by" => [ "type" => "user" , "user_id" => env('SIGNUP_USER') ] ];

        $custToUser->save();

        //event( new UserAddedToCustomerEvent( $custToUser ) );
        Log::notice( 'Signup form created ' . $user->username . ' via CustomerToUser ID [' . $custToUser->id . '] to ' . $custToUser->customer->name );

        // Send new user e-mail
        event( new UserCreatedEvent( $user ) );
        Log::notice( 'Signup form created a User with ID ' . $user->id );

        AlertContainer::push( "User created. A welcome email is being sent to {$form["useremail"]} with "
            . "instructions on how to set their password. ", Alert::SUCCESS );

        return redirect( route('login@showForm') );

    }
}