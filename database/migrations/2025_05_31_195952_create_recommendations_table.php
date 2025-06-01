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
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();

            $table->string('name')->default('default');

            $table->morphs('source');
            $table->morphs('target');

            $table->bigInteger('score')->default(0);
            $table->unsignedInteger('position')->nullable();

            $table->timestampsTz();

            $table->index(['source_type', 'source_id', 'name', 'position'], 'idx_recommendation_source_position');
            $table->index(['source_type', 'source_id', 'target_type', 'target_id'], 'idx_recommendation_source_target');
            $table->index('score', 'idx_recommendation_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
