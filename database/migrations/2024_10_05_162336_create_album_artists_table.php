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
        Schema::create('album_artist', function (Blueprint $table) {
            $table->id();

            $table->foreignId('album_id')
                ->references('id')
                ->on('albums')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('artist_id')
                ->references('id')
                ->on('artists')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['album_id', 'artist_id'], 'idx_album_artist_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('album_artists');
    }
};
