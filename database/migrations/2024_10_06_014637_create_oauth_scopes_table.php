<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_scopes', function (Blueprint $table) {
            $table->text('id')->primary();
            $table->text('description');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_scopes');
    }
};
