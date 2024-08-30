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

            $table->caseInsensitiveText('name');
            $table->text('slug')->unique();

            $table->text('path');
            $table->enum('type', ['music', 'podcast', 'audiobook', 'movie', 'tv_show']);

            $table->unsignedInteger('order');

            $table->timestampTz('last_scan')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libraries');
    }
};
