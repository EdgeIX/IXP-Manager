<?php

namespace IXP\Http\Controllers\EdgeIX;

use Illuminate\Http\Request;
use Illuminate\View\View;

use IXP\Http\Controllers\Controller;
use IXP\Models\Customer;

/**
 * EdgeIX customer-facing MSA page.
 *
 * The order-time MSA gate (EnsureMsaSigned middleware on /order) redirects
 * here when a customer has no executed MSA on record. The page branches:
 *
 *   - signed (standard or custom)   → status panel: signed when / by whom,
 *                                     download link if the PDF is in docstore.
 *   - custom, not signed            → "agreement being finalised" — their
 *                                     legal/our team are negotiating; contact
 *                                     sales, no self-serve flow.
 *   - standard, not signed          → acceptance path. Interim: instructions
 *                                     + contact sales. Will become the
 *                                     embedded e-sign flow (SignNow) once the
 *                                     provider account exists.
 *
 * See docs/ordering.md and [[signup-msa-project]].
 */
class MsaController extends Controller
{
    public function __construct()
    {
        $this->middleware( 'auth' );
    }

    public function index( Request $r ): View
    {
        /** @var Customer|null $cust */
        $cust = $r->user()?->customer;

        return view( 'msa.index', [
            'msaCust'     => $cust,
            'msaExempt'   => $cust ? !$cust->msaRequired() : false,
            'msaSigned'   => $cust?->msaSigned() ?? false,
            'msaIsCustom' => $cust?->msa_type === Customer::MSA_TYPE_CUSTOM,
            'msaDocument' => $cust?->msaDocument,
            'msaSignedBy' => $cust?->msaSignedBy,
        ] );
    }
}
