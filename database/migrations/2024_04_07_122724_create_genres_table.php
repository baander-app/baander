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
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('parent_id')->nullable();

            $table->text('name');
            $table->text('slug')->unique('idx_genres_slug');

            $table->timestampsTz();
        });

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_genres_slug_trgm '.
            'ON genres USING gin (slug gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genres');
    }
};
