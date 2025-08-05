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
        Schema::create('images', function (Blueprint $table) {
            $table->id();

            $table->text('path');
            $table->text('extension');
            $table->text('mime_type');
            $table->text('blurhash');
            $table->text('public_id')->unique();
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            $table->morphs('imageable');

            $table->timestampsTz();
        });

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_artists_public_id_trgm '.
            'ON artists USING gin (public_id gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
