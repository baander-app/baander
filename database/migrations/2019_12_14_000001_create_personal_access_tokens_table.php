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
            $table->text('client_fingerprint')->nullable();
            $table->text('session_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->jsonb('ip_history')->nullable();
            $table->integer('ip_change_count')->default(0);
            $table->text('country_code', 2)->nullable();
            $table->text('city')->nullable();
            $table->timestampTz('last_geo_notification_at')->nullable();

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
