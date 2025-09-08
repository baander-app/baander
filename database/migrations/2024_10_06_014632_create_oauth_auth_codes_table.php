<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('code_id')->unique()->index();
            $table->uuid('user_id');
            $table->uuid('client_id');
            $table->jsonb('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_auth_codes');
    }
};
