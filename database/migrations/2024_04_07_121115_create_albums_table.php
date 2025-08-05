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
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique('idx_albums_public_id');

            $table->foreignId('library_id')
                ->references('id')
                ->on('libraries')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->text('title');

            $table->enum('type', [
                'audiobook',
                'bootleg',
                'compilation',
                'demo',
                'ep',
                'interview',
                'live',
                'mixtape',
                'other',
                'remix',
                'single',
                'soundtrack',
                'spoken_word',
                'studio',
            ])->default('studio');

            $table->uuid('mbid')->nullable()->comment('MusicBrainz ID');
            $table->bigInteger('discogs_id')->nullable()->comment('Discogs release ID');
            $table->text('spotify_id')->nullable()->comment('Spotify album ID');

            $table->integer('year')->nullable()->comment('The year the album was released');

            $table->text('label')->nullable()->comment('Record label name');
            $table->text('catalog_number')->nullable()->comment('Catalog number');
            $table->text('barcode')->nullable()->comment('UPC/EAN barcode');

            $table->char('country', 2)->nullable()->comment('Country of release');
            $table->char('language', 3)->nullable()->comment('ISO 639-2 Primary language of the album');
            $table->text('disambiguation')->nullable()->comment('Disambiguation comment');
            $table->text('annotation')->nullable()->comment('Additional notes or annotation');

            $table->timestampsTz();

            $table->index('library_id', 'fk_albums_library_id');
            $table->index('catalog_number', 'idx_albums_catalog_number');
            $table->index('barcode', 'idx_albums_barcode');
            $table->index('mbid', 'idx_albums_mbid');
            $table->index('discogs_id', 'idx_albums_discogs_id');
            $table->index('spotify_id', 'idx_albums_spotify_id');
        });

        $sql = <<<SQL
CREATE INDEX idx_albums_title_fts
          ON albums
       USING pgroonga (title pgroonga_text_full_text_search_ops_v2)
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
        Schema::dropIfExists('albums');
    }
};
