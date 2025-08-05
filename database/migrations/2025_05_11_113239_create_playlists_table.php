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
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            $table->text('name');
            $table->text('description')->nullable();

            $table->boolean('is_public')->default(false);
            $table->boolean('is_collaborative')->default(false);
            $table->boolean('is_smart')->default(false);
            $table->jsonb('smart_rules')->nullable();

            $table->timestampsTz();
        });

        $sql = <<<SQL
CREATE INDEX idx_playlists_title_fts
          ON playlists
       USING pgroonga (name pgroonga_text_full_text_search_ops_v2)
               WITH (tokenizer='TokenNgram("unify_alphabet", false)',
                    normalizers = 'NormalizerNFKC130');
SQL;

        DB::statement($sql);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_playlists_public_id_trgm '.
            'ON playlists USING gin (public_id gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
