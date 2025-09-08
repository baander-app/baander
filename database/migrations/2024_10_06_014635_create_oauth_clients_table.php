<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('secret')->nullable();
            $table->text('provider')->nullable();
            $table->text('redirect');
            $table->boolean('personal_access_client')->default(false);
            $table->boolean('password_client')->default(false);
            $table->boolean('device_client')->default(false);
            $table->boolean('confidential')->default(true);
            $table->boolean('first_party')->default(false);
            $table->boolean('revoked')->default(false);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
