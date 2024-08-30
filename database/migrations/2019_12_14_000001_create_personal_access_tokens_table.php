<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->text('token')->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('device_operating_system')->nullable();
            $table->text('device_name')->nullable();
            $table->text('device_brand_name')->nullable();
            $table->text('device_model')->nullable();
            $table->text('device_type')->nullable();
            $table->text('client_name')->nullable();
            $table->text('client_version')->nullable();
            $table->text('client_type')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
