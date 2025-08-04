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
        Schema::table('songs', function (Blueprint $table) {
            $table->uuid('mbid')->nullable();
            $table->bigInteger('discogs_id')->nullable();

            $table->unique('mbid', 'idx_songs_mbid');
            $table->unique('discogs_id', 'idx_songs_discogs_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique('idx_songs_mbid');
            $table->dropUnique('idx_songs_discogs_id');
            $table->dropColumn(['mbid', 'discogs_id']);
        });
    }
};
