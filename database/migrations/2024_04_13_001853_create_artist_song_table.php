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
        Schema::create('artist_song', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artist_id')
                ->references('id')
                ->on('artists')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('song_id')
                ->references('id')
                ->on('songs')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->text('role')->nullable();

            $table->timestamps();

            $table->unique(['artist_id', 'song_id', 'role'], 'idx_artist_song_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_album');
    }
};
