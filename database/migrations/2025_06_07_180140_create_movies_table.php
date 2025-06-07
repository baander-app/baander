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
        Schema::create('movies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('library_id')
                ->references('id')
                ->on('libraries')
                ->cascadeOnDelete();

            $table->caseInsensitiveText('title');
            $table->text('slug')->unique();

            $table->integer('year')->nullable();
            $table->text('summary')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
