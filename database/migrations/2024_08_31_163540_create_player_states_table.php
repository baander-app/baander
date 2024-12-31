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
        Schema::create('player_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->boolean('is_active')->default(false);

            $table->boolean('is_playing')->default(false);

            $table->text('name')->comment('Device name');

            $table->text('type')->comment('Device type');

            $table->integer('progress_ms')->nullable();

            $table->integer('volume_percent')->nullable();

            $table->morphs('playable');

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_states');
    }
};
