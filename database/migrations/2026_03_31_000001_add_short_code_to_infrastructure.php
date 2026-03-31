<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'infrastructure', function ( Blueprint $table ) {
            $table->string( 'short_code', 10 )->nullable()->after( 'shortname' );
        } );
    }

    public function down(): void
    {
        Schema::table( 'infrastructure', function ( Blueprint $table ) {
            $table->dropColumn( 'short_code' );
        } );
    }
};
