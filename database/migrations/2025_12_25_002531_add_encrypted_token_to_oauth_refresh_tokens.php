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
        Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
            $table->text('encrypted_token')->nullable()->after('token_id');
            $table->index('encrypted_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['encrypted_token']);
            $table->dropColumn('encrypted_token');
        });
    }
};
