<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EdgeIX ordering Phase 3 — the orders core.
 *
 * port_order ("order" is an SQL reserved word): one row per customer port
 * order, carrying the wizard capture + state machine timestamps.
 * port_order_port: one row per reserved switch port — these rows ARE the
 * reservation; the stock/sellable queries exclude switch ports held by any
 * open order. See docs/ordering.md "Orders data model".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'port_order', function( Blueprint $table ) {
            $table->increments( 'id' );
            $table->unsignedInteger( 'custid' )->index();
            $table->unsignedInteger( 'user_id' )->nullable()->comment( 'portal user who placed it' );
            $table->string( 'kind', 16 )->default( 'new_port' )->comment( 'new_port | add_lag | upgrade' );
            $table->unsignedInteger( 'target_virtual_interface_id' )->nullable()->comment( 'the existing service for add_lag / upgrade orders' );
            $table->string( 'state', 24 )->default( 'submitted' )->index();
            $table->unsignedInteger( 'locationid' )->comment( 'DC / facility' );
            $table->unsignedInteger( 'port_type_id' );
            $table->unsignedTinyInteger( 'quantity' )->default( 1 )->comment( '2+ = LACP LAG on one switch' );
            $table->boolean( 'tagged' )->default( false );
            $table->unsignedInteger( 'vlan_tag' )->nullable();
            $table->json( 'macs' )->nullable()->comment( 'up to 2 per MSA Schedule A; null = provide later' );
            $table->string( 'delivery_contact', 255 )->nullable();
            $table->string( 'po_number', 64 )->nullable();
            $table->date( 'preferred_golive' )->nullable();
            $table->text( 'admin_notes' )->nullable();
            $table->timestamp( 'reserved_until' )->nullable()->comment( 'hold expiry while state=submitted' );
            $table->timestamp( 'approved_at' )->nullable();
            $table->unsignedInteger( 'approved_by' )->nullable()->comment( 'user id; null = auto-approved by policy' );
            $table->timestamp( 'provisioned_at' )->nullable();
            $table->timestamp( 'loa_issued_at' )->nullable();
            $table->timestamp( 'activated_at' )->nullable();
            $table->timestamp( 'cancelled_at' )->nullable();
            $table->string( 'cancel_reason', 255 )->nullable();
            $table->unsignedInteger( 'virtual_interface_id' )->nullable()->comment( 'set by auto-provisioning' );
            $table->timestamps();
        } );

        Schema::create( 'port_order_port', function( Blueprint $table ) {
            $table->increments( 'id' );
            $table->unsignedInteger( 'port_order_id' )->index();
            $table->unsignedInteger( 'switchportid' )->index();
            $table->unsignedInteger( 'patch_panel_port_id' )->nullable()->comment( 'panel port at reservation time — feeds the LOA' );
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'port_order_port' );
        Schema::dropIfExists( 'port_order' );
    }
};
