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
        Schema::create('user_two_factor_authentications', function (Blueprint $table) {
            $table->id();

            $table->morphs('authenticatable', 'two_factor_authenticatable_index');

            $table->text('shared_secret')->unique();

            $table->timestampTz('enabled_at');

            $table->text('label');

            $table->unsignedInteger('digits')->default(6);

            $table->unsignedInteger('seconds')->default(30);

            $table->unsignedInteger('window')->default(0);

            $table->text('algorithm')->default('sha1');

            $table->text('recovery_codes')->nullable();

            $table->timestampTz('recovery_codes_generated_at')->nullable();

            $table->jsonb('safe_devices')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_two_factor_authentications');
    }
};
