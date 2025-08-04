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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique('idx_videos_public_id');

            $table->text('path');
            $table->text('hash')->unique();
            $table->integer('duration')->nullable();
            $table->integer('height')->nullable();
            $table->integer('width')->nullable();
            $table->integer('video_bitrate')->nullable();
            $table->integer('framerate')->nullable();
            $table->jsonb('probe')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
