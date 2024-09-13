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
        Schema::create('player_queues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->index('user_id', 'player_queues_user_id_index');

            $table->foreignId('song_id')
                ->references('id')
                ->on('songs')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->integer('order');

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_queues', function (Blueprint $table) {
            $table->dropIndex('player_queues_user_id_index');
        });
        Schema::dropIfExists('player_queues');
    }
};
