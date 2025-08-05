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
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();

            $table->text('name');
            $table->text('slug')->unique();

            $table->text('path');
            $table->enum('type', ['music', 'podcast', 'audiobook', 'movie', 'tv_show']);

            $table->unsignedInteger('order');

            $table->timestampTz('last_scan')->nullable();

            $table->timestampsTz();
        });

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_libraries_slug_trgm '.
            'ON libraries USING gin (slug gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libraries');
    }
};
