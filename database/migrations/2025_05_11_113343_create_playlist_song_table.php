<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('playlist_song', function (Blueprint $table) {
            $table->id();

            $table->foreignId('playlist_id')
                ->constrained()
                ->onDelete('cascade');


            $table->foreignId('song_id')
                ->constrained()
                ->onDelete('cascade');


            $table->integer('position')->default(0);

            $table->timestampsTz();

            $table->unique(['playlist_id', 'song_id'], 'idx_playlist_song_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlist_song');
    }
};
