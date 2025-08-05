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
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique('idx_movies_public_id');

            $table->foreignId('library_id')
                ->references('id')
                ->on('libraries')
                ->cascadeOnDelete();

            $table->text('title');

            $table->integer('year')->nullable();
            $table->text('summary')->nullable();

            $table->timestampsTz();
        });

        $sql = <<<SQL
CREATE INDEX idx_movies_title_fts
          ON movies
       USING pgroonga (title pgroonga_text_full_text_search_ops_v2)
               WITH (tokenizer='TokenNgram("unify_alphabet", false)',
                    normalizers = 'NormalizerNFKC130');
SQL;

        DB::statement($sql);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_movies_public_id_trgm '.
            'ON movies USING gin (public_id gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
