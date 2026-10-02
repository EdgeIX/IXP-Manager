<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;

use IXP\Models\Customer;
use IXP\Models\DocstoreCustomerDirectory;
use IXP\Models\DocstoreCustomerFile;
use IXP\Models\User;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * EdgeIX admin: record / upload an executed MSA for a customer.
 *
 * This is the tool for the existing book and for custom agreements:
 * MSAs executed manually to date (paper, emailed PDFs, negotiated custom
 * terms) are recorded here so the order-time gate passes. The PDF upload
 * is optional — historic agreements may only exist on paper, in which case
 * notes (signatory, date context, where the copy lives) are required.
 *
 * Uploaded PDFs land in the customer's docstore under an "Agreements"
 * directory with min_privs = custadmin, so the customer's admins can see
 * their own executed MSA from the /msa page.
 *
 * Routes are superuser-only (web-auth-superuser.php).
 */
class MsaAdminController extends Controller
{
    private const AGREEMENTS_DIR = 'Agreements';

    public function edit( Request $r, Customer $cust ): View
    {
        return view( 'msa.admin', [
            'msaCust'     => $cust,
            'msaDocument' => $cust->msaDocument,
            'msaSignedBy' => $cust->msaSignedBy,
        ] );
    }

    public function update( Request $r, Customer $cust ): RedirectResponse
    {
        $r->validate( [
            'msa_status'    => 'required|in:' . Customer::MSA_STATUS_UNSIGNED . ',' . Customer::MSA_STATUS_SIGNED,
            'msa_type'      => 'required|in:' . Customer::MSA_TYPE_STANDARD . ',' . Customer::MSA_TYPE_CUSTOM,
            'msa_signed_at' => 'required_if:msa_status,' . Customer::MSA_STATUS_SIGNED . '|nullable|date',
            'msa_notes'     => 'nullable|string|max:65535',
            'msa_document'  => 'nullable|file|mimes:pdf|max:20480',
        ] );

        $signed = $r->msa_status === Customer::MSA_STATUS_SIGNED;

        // A signed record needs evidence: an uploaded PDF now, one already on
        // file, or notes saying where the executed agreement lives.
        if( $signed && !$r->hasFile( 'msa_document' ) && !$cust->msa_document_id && !trim( (string)$r->msa_notes ) ) {
            return redirect()->back()->withInput()->withErrors( [
                'msa_notes' => 'Upload the executed MSA PDF, or add notes recording the signatory and where the executed copy is held.',
            ] );
        }

        if( $r->hasFile( 'msa_document' ) ) {
            $uploadedFile = $r->file( 'msa_document' );

            $dir = DocstoreCustomerDirectory::firstOrCreate( [
                'cust_id' => $cust->id,
                'name'    => self::AGREEMENTS_DIR,
            ] );

            $path = $uploadedFile->store( (string)$cust->id, 'docstore_customers' );

            $file = DocstoreCustomerFile::create( [
                'name'                           => sprintf( 'MSA - %s.pdf', $cust->abbreviatedName ?: $cust->name ),
                'description'                    => 'Executed Master Services Agreement (recorded by ' . $r->user()->username . ')',
                'cust_id'                        => $cust->id,
                'min_privs'                      => User::AUTH_CUSTADMIN,
                'path'                           => $path,
                'sha256'                         => hash_file( 'sha256', $uploadedFile ),
                'created_by'                     => $r->user()->id,
                'file_last_updated'              => now(),
                'docstore_customer_directory_id' => $dir->id,
            ] );

            $cust->msa_document_id = $file->id;
        }

        $cust->msa_type   = $r->msa_type;
        $cust->msa_status = $r->msa_status;
        $cust->msa_notes  = trim( (string)$r->msa_notes ) ?: null;

        if( $signed ) {
            $cust->msa_signed_at = $r->msa_signed_at;
            // Manual record: the signatory is in the notes, not a portal user.
            // Leave msa_signed_by_user_id / msa_signature_provider_id to the
            // e-sign flow.
        } else {
            $cust->msa_signed_at = null;
        }

        $cust->save();

        Log::info( sprintf( "MSA: [%d|%s] recorded as %s/%s by %s", $cust->id, $cust->name, $cust->msa_type, $cust->msa_status, $r->user()->username ) );

        AlertContainer::push( "MSA record updated for <em>" . e( $cust->name ) . "</em>.", Alert::SUCCESS );

        return redirect()->route( 'msa-admin@edit', [ 'cust' => $cust->id ] );
    }
}
