<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->id()->primary();
            $table->text('token_id')->unique()->index();
            $table->bigInteger('access_token_id');
            $table->boolean('revoked')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('access_token_id')->references('id')->on('oauth_access_tokens')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
    }
};
