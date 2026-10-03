<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EdgeIX ordering: per-type OFFERING map — which locations offer a port
 * type (e.g. 400G only at certain sites).
 *
 * No rows for a type = offered wherever its optics are detected (the
 * original behaviour). Rows present = authoritative for that type:
 * sellable stock, the order form and low-stock alerting apply ONLY at the
 * listed sites — and alerting fires there even when the site currently
 * has ZERO ports of the type (capable-but-empty). See docs/ordering.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'port_type_location', function( Blueprint $table ) {
            $table->increments( 'id' );
            $table->unsignedInteger( 'port_type_id' );
            $table->unsignedInteger( 'locationid' );
            $table->timestamps();

            $table->unique( [ 'port_type_id', 'locationid' ] );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'port_type_location' );
    }
};
