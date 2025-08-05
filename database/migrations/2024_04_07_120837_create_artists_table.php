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

            $table->text('name');
            $table->char('country', 2)->nullable()->comment('ISO 3166-1 alpha-2 country code');
            $table->text('gender')->nullable()->comment('Artist gender');
            $table->text('type')->nullable()->comment('Artist type: Person, Group, Orchestra, Choir, Character, Other');

            // Life span information
            $table->date('life_span_begin')->nullable()->comment('Birth date or formation date');
            $table->date('life_span_end')->nullable()->comment('Death date or dissolution date');

            // Additional metadata
            $table->text('disambiguation')->nullable()->comment('Disambiguation comment');
            $table->text('sort_name')->nullable()->comment('Name for sorting purposes');

            $table->uuid('mbid')->nullable()->comment('MusicBrainz ID');
            $table->bigInteger('discogs_id')->nullable()->comment('Discogs release ID');
            $table->text('spotify_id')->nullable()->comment('Spotify album ID');

            // Add indexes for common queries
            $table->index('country', 'idx_artists_country');
            $table->index('type', 'idx_artists_type');

            $table->timestampsTz();
        });

        $sql = <<<SQL
CREATE INDEX idx_artists_title_fts
          ON artists
       USING pgroonga (name pgroonga_text_full_text_search_ops_v2)
               WITH (tokenizer='TokenNgram("unify_alphabet", false)',
                    normalizers = 'NormalizerNFKC130');
SQL;

        DB::statement($sql);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_artists_public_id_trgm '.
            'ON artists USING gin (public_id gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
