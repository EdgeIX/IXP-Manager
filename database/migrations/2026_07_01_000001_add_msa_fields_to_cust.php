<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add MSA (Master Service Agreement) tracking to the cust table.
     *
     * These fields are populated when a customer signs the MSA via the
     * e-signature integration (see [[signup-msa-project]]).
     *
     *   msa_status                  → 'unsigned' | 'pending' | 'signed' | 'declined'
     *   msa_signed_at               → timestamp of completion
     *   msa_signed_by_user_id       → which of the customer's users signed
     *   msa_document_id             → docstore_customer_file row holding the signed PDF
     *   msa_signature_provider_id   → external ID from SignNow / DocuSign / etc.
     *
     * Phase 1: fields exist but nothing enforces them.
     * Phase 2: middleware gates service ordering on msa_status = 'signed'.
     */
    public function up(): void
    {
        Schema::table( 'cust', function ( Blueprint $table ) {
            $table->string( 'msa_status', 16 )->default( 'unsigned' )->after( 'irrdb' );
            $table->timestamp( 'msa_signed_at' )->nullable()->after( 'msa_status' );
            $table->unsignedInteger( 'msa_signed_by_user_id' )->nullable()->after( 'msa_signed_at' );
            $table->unsignedInteger( 'msa_document_id' )->nullable()->after( 'msa_signed_by_user_id' );
            $table->string( 'msa_signature_provider_id', 128 )->nullable()->after( 'msa_document_id' );
        });
    }

    public function down(): void
    {
        Schema::table( 'cust', function ( Blueprint $table ) {
            $table->dropColumn( [
                'msa_status',
                'msa_signed_at',
                'msa_signed_by_user_id',
                'msa_document_id',
                'msa_signature_provider_id',
            ] );
        });
    }
};
