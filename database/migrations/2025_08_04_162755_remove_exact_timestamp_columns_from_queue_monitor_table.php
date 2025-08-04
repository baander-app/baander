<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('queue_monitor', function (Blueprint $table) {
            $table->dropColumn(['started_at_exact', 'finished_at_exact']);
        });
    }

    public function down()
    {
        Schema::table('queue_monitor', function (Blueprint $table) {
            $table->text('started_at_exact')->nullable();
            $table->text('finished_at_exact')->nullable();
        });
    }
};