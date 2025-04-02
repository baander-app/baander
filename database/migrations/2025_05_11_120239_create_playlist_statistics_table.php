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
        Schema::create('playlist_statistics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('playlist_id')
                ->constrained()
                ->onDelete('cascade');

            $table->integer('views')->default(0);
            $table->integer('plays')->default(0);
            $table->integer('shares')->default(0);
            $table->integer('favorites')->default(0);

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlist_statistics');
    }
};
