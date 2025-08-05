<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_party_credentials', function (Blueprint $table) {
            $table->id();
            $table->text('public_id')->unique('idx_third_party_credentials_public_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // 'lastfm', 'spotify', 'discogs', etc.
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('meta')->nullable(); // All provider-specific data
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index(['provider']);
        });

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_third_party_credentials_public_id_trgm '.
            'ON third_party_credentials USING gin (public_id gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_credentials');
    }
};