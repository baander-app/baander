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
        // Index for songs table to optimize duration-based queries
        Schema::table('songs', function (Blueprint $table) {
            $table->index(['year', 'length'], 'idx_songs_year_length');
        });

        // Index for user_media_activities to optimize user listening history queries
        Schema::table('user_media_activities', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_user_activity_user_time');
        });

        // Index for playlist_song to optimize playlist ordering queries
        Schema::table('playlist_song', function (Blueprint $table) {
            $table->index(['playlist_id', 'position'], 'idx_playlist_position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex('idx_songs_year_length');
        });

        Schema::table('user_media_activities', function (Blueprint $table) {
            $table->dropIndex('idx_user_activity_user_time');
        });

        Schema::table('playlist_song', function (Blueprint $table) {
            $table->dropIndex('idx_playlist_position');
        });
    }
};
