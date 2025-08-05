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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique();
            $table->text('name');
            $table->text('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('password');
            $table->text('remember_token')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'idx_users_public_id');
        });

        $sql = <<<SQL
CREATE INDEX idx_users_name_fts
          ON users
       USING pgroonga (name pgroonga_text_full_text_search_ops_v2)
               WITH (tokenizer='TokenNgram("unify_alphabet", false)',
                    normalizers = 'NormalizerNFKC130');
SQL;

        DB::statement($sql);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_users_public_id_trgm '.
            'ON users USING gin (public_id gin_trgm_ops)'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_users_email_trgm '.
            'ON users USING gin (email gin_trgm_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
