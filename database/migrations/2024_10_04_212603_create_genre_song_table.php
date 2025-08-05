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
        Schema::create('genre_song', function (Blueprint $table) {
            $table->id();

            $table->foreignId('genre_id')
                ->references('id')
                ->on('genres')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('song_id')
                ->references('id')
                ->on('songs')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['genre_id', 'song_id'], 'idx_genre_song_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genre_song');
    }
};
