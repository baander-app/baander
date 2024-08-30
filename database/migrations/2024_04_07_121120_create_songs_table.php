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
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique();
            $table->foreignId('album_id')->index()->constrained()->cascadeOnDelete();

            $table->caseInsensitiveText('title');
            $table->text('path');
            $table->integer('size');
            $table->text('mime_type');
            $table->float('length')->nullable();
            $table->text('lyrics')->nullable();
            $table->integer('track')->nullable();
            $table->integer('disc')->nullable();
            $table->integer('modified_time')->nullable();
            $table->integer('year')->nullable();
            $table->text('comment')->nullable();
            $table->string('hash')->comment('sha hash of the file')->index()->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
