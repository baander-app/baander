<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateQueueMonitorTable extends Migration
{
    public function up()
    {
        Schema::create(config('queue-monitor.table'), function (Blueprint $table) {
            $table->increments('id');

            $table->text('job_id')->index();
            $table->text('name')->nullable();
            $table->text('queue')->nullable();

            $table->timestamp('started_at')->nullable()->index();
            $table->text('started_at_exact')->nullable();

            $table->timestamp('finished_at')->nullable();
            $table->text('finished_at_exact')->nullable();

            $table->float('time_elapsed', 12, 6)->nullable()->index();

            $table->boolean('failed')->default(false)->index();

            $table->integer('attempt')->default(0);
            $table->integer('progress')->nullable();

            $table->text('exception')->nullable();
            $table->text('exception_message')->nullable();
            $table->text('exception_class')->nullable();

            $table->text('data')->nullable();
        });
    }

    public function down()
    {
        Schema::drop(config('queue-monitor.table'));
    }
}
