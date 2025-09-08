<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_device_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('device_code')->unique()->index();
            $table->text('user_code', 20)->unique()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('client_id');
            $table->jsonb('scopes')->nullable();
            $table->text('verification_uri');
            $table->text('verification_uri_complete')->nullable();
            $table->timestampTz('expires_at');
            $table->integer('interval')->default(5);
            $table->timestampTz('last_polled_at')->nullable();
            $table->boolean('approved')->default(false);
            $table->boolean('denied')->default(false);
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_device_codes');
    }
};
