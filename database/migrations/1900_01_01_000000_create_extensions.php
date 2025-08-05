<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    private array $extensions = [
        'citext',
        'uuid-ossp',
        'pgcrypto',
        'pg_trgm',
        'ltree',
        'pg_stat_statements',
        'pgroonga',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->extensions as $extension) {
            Schema::createExtensionIfNotExists($extension);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->extensions as $extension) {
            Schema::dropExtensionIfExists($extension);
        }
    }
};
