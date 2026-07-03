<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add terms_version_accepted to the cust table.
     *
     * Stamped at signup (when the T&Cs consent checkbox is ticked) and later at
     * MSA acceptance. Compared to config('signup.terms.version') to decide
     * whether the customer needs to re-consent at their next order.
     */
    public function up(): void
    {
        Schema::table( 'cust', function ( Blueprint $table ) {
            $table->string( 'terms_version_accepted', 32 )->nullable()->after( 'msa_signature_provider_id' );
        });
    }

    public function down(): void
    {
        Schema::table( 'cust', function ( Blueprint $table ) {
            $table->dropColumn( 'terms_version_accepted' );
        });
    }
};
