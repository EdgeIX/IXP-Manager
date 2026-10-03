<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EdgeIX ordering Phase 3 — port-type catalogue + automatic transceiver
 * detection (see docs/ordering.md "OPEN DESIGN ISSUE — port speed visibility").
 *
 * port_type: the admin-managed catalogue of sellable port types (10G LR,
 * 100G LR4, …). `match_patterns` is one case-insensitive regex per line,
 * matched against the transceiver model/description detected via the
 * ENTITY-MIB walk (switch:detect-transceivers). First match by `priority`
 * wins. Adding e.g. 25G later is a catalogue row, not code.
 *
 * switchport additions:
 *   detected_xcvr          → transceiver model string from ENTITY-MIB
 *                            (e.g. "QSFP-100G-LR4"), null = nothing detected
 *   detected_xcvr_at       → when detection last saw it
 *   port_type_id           → catalogue entry mapped from detected_xcvr
 *   port_type_override_id  → admin override — wins over detection (lying
 *                            third-party optics, odd cases)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'port_type', function( Blueprint $table ) {
            $table->increments( 'id' );
            $table->string( 'name', 64 )->unique();
            $table->unsignedInteger( 'speed' )->comment( 'Mbps, matches ifHighSpeed convention' );
            $table->boolean( 'active' )->default( true );
            $table->unsignedInteger( 'priority' )->default( 100 )->comment( 'match order — lowest first' );
            $table->text( 'match_patterns' )->nullable()->comment( 'one case-insensitive regex per line, matched against detected transceiver model + description' );
            $table->unsignedInteger( 'low_stock_threshold' )->nullable()->comment( 'alert when sellable stock per location drops below this' );
            $table->text( 'notes' )->nullable();
            $table->timestamps();
        } );

        Schema::table( 'switchport', function( Blueprint $table ) {
            $table->string( 'detected_xcvr', 128 )->nullable()->after( 'mauAutoNegAdminState' );
            $table->timestamp( 'detected_xcvr_at' )->nullable()->after( 'detected_xcvr' );
            $table->unsignedInteger( 'port_type_id' )->nullable()->after( 'detected_xcvr_at' );
            $table->unsignedInteger( 'port_type_override_id' )->nullable()->after( 'port_type_id' );

            $table->index( 'port_type_id' );
        } );

        // Seed the catalogue with what we sell today. LR4 patterns are listed
        // at higher priority (lower number) than single-lambda LR so
        // "100G-LR4" never falls through to the "100G-LR" type.
        $now = now();
        DB::table( 'port_type' )->insert( [
            [ 'name' => '10GBASE-LR',           'speed' => 10000,  'active' => 1, 'priority' => 10, 'match_patterns' => "10G-?BASE-?LR(?![0-9M])\n10G-?LR(?![0-9M])", 'low_stock_threshold' => null, 'notes' => null, 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '40GBASE-LR4',          'speed' => 40000,  'active' => 1, 'priority' => 10, 'match_patterns' => "40G-?BASE-?LR4\n40G-?LR4", 'low_stock_threshold' => null, 'notes' => null, 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '100GBASE-LR4',         'speed' => 100000, 'active' => 1, 'priority' => 10, 'match_patterns' => "100G-?BASE-?LR4\n100G-?LR4", 'low_stock_threshold' => null, 'notes' => null, 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '100GBASE-LR/FR',       'speed' => 100000, 'active' => 1, 'priority' => 20, 'match_patterns' => "100G-?BASE-?(LR|FR)(?![0-9])\n100G-?(LR|FR)(?![0-9])\n100G-?DR", 'low_stock_threshold' => null, 'notes' => 'Single-lambda 100G (LR/FR/DR)', 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '400GBASE-LR4',         'speed' => 400000, 'active' => 1, 'priority' => 10, 'match_patterns' => "400G-?BASE-?LR4\n400G-?LR4", 'low_stock_threshold' => null, 'notes' => null, 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '10G (PSM4 breakout)',  'speed' => 10000,  'active' => 1, 'priority' => 15, 'match_patterns' => "40G-?PSM4\n40G-?PLR4", 'low_stock_threshold' => null, 'notes' => '4x10G legs of a 40G PSM4/PLR4 in a QSFP cage — each leg is a sellable 10G port', 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '25G (PSM4 breakout)',  'speed' => 25000,  'active' => 0, 'priority' => 15, 'match_patterns' => "100G-?PSM4\n100G-?PLR4", 'low_stock_threshold' => null, 'notes' => 'Not sold yet — enable when 25G launches', 'created_at' => $now, 'updated_at' => $now ],
            [ 'name' => '25GBASE-LR',           'speed' => 25000,  'active' => 0, 'priority' => 20, 'match_patterns' => "25G-?BASE-?LR\n25G-?LR", 'low_stock_threshold' => null, 'notes' => 'Not sold yet — enable when 25G launches', 'created_at' => $now, 'updated_at' => $now ],
        ] );
    }

    public function down(): void
    {
        Schema::table( 'switchport', function( Blueprint $table ) {
            $table->dropIndex( [ 'port_type_id' ] );
            $table->dropColumn( [ 'detected_xcvr', 'detected_xcvr_at', 'port_type_id', 'port_type_override_id' ] );
        } );

        Schema::dropIfExists( 'port_type' );
    }
};
