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
        Schema::create('movie_video', function (Blueprint $table) {
            $table->id();

            $table->foreignId('movie_id')
                ->references('id')
                ->on('movies')
                ->cascadeOnDelete();

            $table->foreignId('video_id')
                ->references('id')
                ->on('videos')
                ->cascadeOnDelete();

            $table->integer('order')->default(0);

            $table->index('movie_id', 'idx_movie_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movie_video');
    }
};
