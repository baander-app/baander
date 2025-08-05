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
        Schema::create('user_media_activities', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique('idx_user_media_activities_public_id');

            $table->foreignId('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->morphs('user_media_activityable');

            $table->unsignedBigInteger('play_count')->nullable();

            $table->boolean('love')->default(false);

            $table->timestampTz('last_played_at')->nullable();

            $table->text('last_platform')->nullable();

            $table->text('last_player')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_media_activities');
    }
};
