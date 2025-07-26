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
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // 'lastfm', 'spotify', 'discogs', etc.
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('meta')->nullable(); // All provider-specific data
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index(['provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_credentials');
    }
};