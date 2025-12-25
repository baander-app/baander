<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add locked_fields to albums
        Schema::table('albums', function (Blueprint $table) {
            $table->jsonb('locked_fields')->nullable()->default(json_encode([]))->comment('Fields that are locked from external sync');
        });

        // Add locked_fields to songs
        Schema::table('songs', function (Blueprint $table) {
            $table->jsonb('locked_fields')->nullable()->default(json_encode([]))->comment('Fields that are locked from external sync');
        });

        // Add locked_fields to artists
        Schema::table('artists', function (Blueprint $table) {
            $table->jsonb('locked_fields')->nullable()->default(json_encode([]))->comment('Fields that are locked from external sync');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('locked_fields');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('locked_fields');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn('locked_fields');
        });
    }
};
