<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->id()->primary();
            $table->text('token_id')->unique()->index();
            $table->bigInteger('user_id')->nullable()->index();
            $table->bigInteger('client_id');
            $table->text('name')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
    }
};
