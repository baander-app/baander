<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
           $table->bigInteger('discogs_id')->nullable();
           $table->uuid('mbid')->nullable();
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->bigInteger('discogs_id')->nullable();
            $table->uuid('mbid')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn(['discogs_id', 'mbid']);
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn(['discogs_id', 'mbid']);
        });
    }
};
