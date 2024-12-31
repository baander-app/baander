<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::createExtensionIfNotExists('uuid-ossp');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropExtensionIfExists('uuid-ossp');
    }
};
