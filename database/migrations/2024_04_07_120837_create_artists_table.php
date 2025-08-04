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
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique('idx_artists_public_id');

            $table->caseInsensitiveText('name');
            $table->char('country', 2)->nullable()->comment('ISO 3166-1 alpha-2 country code');
            $table->caseInsensitiveText('gender')->nullable()->comment('Artist gender');
            $table->caseInsensitiveText('type')->nullable()->comment('Artist type: Person, Group, Orchestra, Choir, Character, Other');

            // Life span information
            $table->date('life_span_begin')->nullable()->comment('Birth date or formation date');
            $table->date('life_span_end')->nullable()->comment('Death date or dissolution date');

            // Additional metadata
            $table->text('disambiguation')->nullable()->comment('Disambiguation comment');
            $table->caseInsensitiveText('sort_name')->nullable()->comment('Name for sorting purposes');

            // Add indexes for common queries
            $table->index('country', 'idx_artists_country');
            $table->index('type', 'idx_artists_type');

            $table->timestampsTz();

            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
