<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EdgeIX ordering: "served via" sites.
 *
 * location.served_via_locationid: this site is passive/campus — customers
 * here are served from the named demarc site (e.g. Equinix SY3/SY4/SY5
 * served via "Equinix SY1/SY2"). The order form lists the site so campus
 * customers FIND it, but stock, reservation and the LOA all resolve to the
 * demarc site, and the customer is told to arrange the campus x-connect.
 *
 * port_order.customer_locationid: the site the customer actually selected
 * when it was a served-via alias (order.locationid always holds the
 * resolved demarc site). See docs/ordering.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'location', function( Blueprint $table ) {
            $table->unsignedInteger( 'served_via_locationid' )->nullable()->after( 'pdb_facility_id' )
                ->comment( 'EdgeIX: passive/campus site served from this demarc location' );
        } );

        Schema::table( 'port_order', function( Blueprint $table ) {
            $table->unsignedInteger( 'customer_locationid' )->nullable()->after( 'locationid' )
                ->comment( 'site the customer selected when ordering via a served-via alias' );
        } );
    }

    public function down(): void
    {
        Schema::table( 'location', function( Blueprint $table ) {
            $table->dropColumn( 'served_via_locationid' );
        } );

        Schema::table( 'port_order', function( Blueprint $table ) {
            $table->dropColumn( 'customer_locationid' );
        } );
    }
};
