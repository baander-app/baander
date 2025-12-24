<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_metadata', function (Blueprint $table) {
            $table->id();

            // Reference to OAuth token
            $table->text('token_id')->unique()->index();
            $table->text('user_agent')->nullable();
            $table->text('device_operating_system')->nullable();
            $table->text('device_name')->nullable();

            $table->text('client_fingerprint')->nullable();
            $table->text('session_id')->nullable()->index();

            $table->ipAddress()->nullable()->index();
            $table->jsonb('ip_history')->nullable();
            $table->integer('ip_change_count')->default(0);

            // Geolocation tracking (from Sanctum)
            $table->string('country_code', 10)->nullable();
            $table->text('city')->nullable();
            $table->timestampTz('last_geo_notification_at')->nullable();

            // Broadcast token (from Sanctum)
            $table->uuid('broadcast_token')->nullable();

            $table->timestampsTz();

            $table->foreign('token_id')
                ->references('token_id')
                ->on('oauth_access_tokens')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_metadata');
    }
};
