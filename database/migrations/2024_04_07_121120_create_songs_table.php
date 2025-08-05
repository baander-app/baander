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
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique();

            $table->foreignId('album_id')
                ->references('id')
                ->on('albums')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->index('album_id');

            $table->text('title');
            $table->text('path');
            $table->integer('size');
            $table->text('mime_type');
            $table->float('length')->nullable();
            $table->text('lyrics')->nullable();
            $table->integer('track')->nullable();
            $table->integer('disc')->nullable();
            $table->integer('modified_time')->nullable();
            $table->integer('year')->nullable();
            $table->text('comment')->nullable();
            $table->string('hash')->comment('sha hash of the file')->index()->nullable();

            $table->timestampsTz();
        });

        $sql = <<<SQL
CREATE INDEX idx_songs_title_fts
          ON songs
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
        Schema::dropIfExists('songs');
    }
};
