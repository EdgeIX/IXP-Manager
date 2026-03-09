<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add reseller_vi_id to virtualinterface table.
     *
     * When a resold customer is assigned a sub-rate service on a reseller's
     * physical port, their VirtualInterface's reseller_vi_id points to the
     * reseller's VirtualInterface. This makes port ownership explicit:
     *
     *   reseller_vi_id = NULL  → customer owns this port directly
     *   reseller_vi_id = {id}  → sub-rate on reseller's port (not PW-eligible)
     */
    public function up(): void
    {
        Schema::table( 'virtualinterface', function ( Blueprint $table ) {
            $table->unsignedInteger( 'reseller_vi_id' )->nullable()->after( 'fastlacp' );

            $table->foreign( 'reseller_vi_id', 'fk_vi_reseller_vi' )
                ->references( 'id' )
                ->on( 'virtualinterface' )
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table( 'virtualinterface', function ( Blueprint $table ) {
            $table->dropForeign( 'fk_vi_reseller_vi' );
            $table->dropColumn( 'reseller_vi_id' );
        });
    }
};
