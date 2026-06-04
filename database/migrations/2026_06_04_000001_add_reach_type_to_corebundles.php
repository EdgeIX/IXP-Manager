<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'corebundles', function ( Blueprint $table ) {
            // Reach classification — orthogonal to the existing `type` column
            // (which classifies link technology: ECMP / L2-LAG / L3-LAG).
            // Values: local, metro, intercapital, international.
            // Used by the pseudowire pricing/capacity stack to drive the
            // capacity dashboard's default filtering and segment-type derivation.
            $table->string( 'reach_type', 20 )
                ->nullable()
                ->after( 'type' )
                ->comment( 'CORE link reach: local/metro/intercapital/international. Drives PW pricing/capacity classification.' );

            $table->index( 'reach_type' );
        } );

        // Backfill: every existing CoreBundle in production is metro today
        // (intra-PoP / intra-city links). Intercapital and international links
        // will be migrated into corebundles as part of the broader plan.
        // Admin can reclassify any specific bundle afterwards.
        DB::table( 'corebundles' )
            ->whereNull( 'reach_type' )
            ->update( [ 'reach_type' => 'metro' ] );
    }

    public function down(): void
    {
        Schema::table( 'corebundles', function ( Blueprint $table ) {
            $table->dropIndex( [ 'reach_type' ] );
            $table->dropColumn( 'reach_type' );
        } );
    }
};
