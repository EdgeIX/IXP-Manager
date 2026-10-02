<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EdgeIX: MSA Phase 2 — custom MSA support.
 *
 * Some customers negotiate a custom MSA (their legal team redlines the
 * standard terms). These are executed externally and recorded by an admin —
 * they never go through the standard e-sign flow, and they are exempt from
 * terms-version re-consent (their terms don't track SIGNUP_TERMS_VERSION).
 *
 *   msa_type   → 'standard' | 'custom'
 *   msa_notes  → admin free text: signatory, agreement date context, where
 *                the paper copy lives when no PDF could be uploaded, etc.
 *
 * See docs/ordering.md and the 2026_07_01 migration for the base msa_* set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'cust', function( Blueprint $table ) {
            $table->string( 'msa_type', 16 )->default( 'standard' )->after( 'msa_status' );
            $table->text( 'msa_notes' )->nullable()->after( 'msa_signature_provider_id' );
        } );
    }

    public function down(): void
    {
        Schema::table( 'cust', function( Blueprint $table ) {
            $table->dropColumn( [ 'msa_type', 'msa_notes' ] );
        } );
    }
};
