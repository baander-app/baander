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
        // Add chain tracking to access tokens
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->uuid('chain_id')->nullable()->after('id')->index();
            $table->timestampTz('last_refreshed_at')->nullable()->after('expires_at');
        });

        // Add chain tracking and usage tracking to refresh tokens
        Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
            $table->uuid('chain_id')->nullable()->after('id')->index();
            $table->bigInteger('previous_refresh_token_id')->nullable()->after('access_token_id')->index();
            $table->timestampTz('used_at')->nullable()->after('expires_at');
            $table->index(['chain_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['chain_id', 'used_at']);
            $table->dropIndex(['previous_refresh_token_id']);
            $table->dropColumn(['chain_id', 'previous_refresh_token_id', 'used_at']);
        });

        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['chain_id']);
            $table->dropColumn(['chain_id', 'last_refreshed_at']);
        });
    }
};
