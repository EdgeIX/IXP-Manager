<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'virtualinterface', function ( Blueprint $table ) {
            $table->string( 'service_reference', 30 )->nullable()->unique()->after( 'description' );
        } );

        // Backfill existing VIs with auto-generated service references.
        // Format: {PREFIX}-{SHORT_CODE}-{PADDED_VI_ID}
        // Requires infrastructure.short_code to be populated first.
        $prefix = env( 'SERVICE_REF_PREFIX', 'EIX' );

        $vis = DB::table( 'virtualinterface as vi' )
            ->join( 'physicalinterface as pi', 'pi.virtualinterfaceid', '=', 'vi.id' )
            ->join( 'switchport as sp', 'sp.id', '=', 'pi.switchportid' )
            ->join( 'switch as sw', 'sw.id', '=', 'sp.switchid' )
            ->join( 'infrastructure as infra', 'infra.id', '=', 'sw.infrastructure' )
            ->whereNotNull( 'infra.short_code' )
            ->select( 'vi.id', 'infra.short_code' )
            ->distinct()
            ->get();

        foreach ( $vis as $vi ) {
            DB::table( 'virtualinterface' )
                ->where( 'id', $vi->id )
                ->whereNull( 'service_reference' )
                ->update( [
                    'service_reference' => $prefix . '-' . strtoupper( $vi->short_code ) . '-' . str_pad( $vi->id, 5, '0', STR_PAD_LEFT ),
                ] );
        }
    }

    public function down(): void
    {
        Schema::table( 'virtualinterface', function ( Blueprint $table ) {
            $table->dropColumn( 'service_reference' );
        } );
    }
};
