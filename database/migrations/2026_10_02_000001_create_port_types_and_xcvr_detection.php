<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        // Seed the catalogue. The canonical row definitions live in
        // Database\Seeders\PortTypeSeeder (single source of truth) — it is
        // idempotent-by-name, so it can also be run standalone later to add
        // newly shipped types to an existing install without touching
        // admin-edited rows:  php artisan db:seed --class=PortTypeSeeder
        ( new \Database\Seeders\PortTypeSeeder() )->run();
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
