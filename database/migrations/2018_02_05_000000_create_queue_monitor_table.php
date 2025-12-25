<?php

use App\Modules\Queue\QueueMonitor\MonitorStatus;
use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('queue_monitor', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_uuid')->nullable();

            $table->text('job_id')->index('idx_queue_monitor_job_id');
            $table->text('name')->nullable();
            $table->text('queue')->nullable();
            $table->enum('status', MonitorStatus::values())->default(MonitorStatus::Running)->after('queue');
            $table->dateTimeTz('queued_at')->nullable();

            $table->timestampTz('started_at')->nullable()->index();

            $table->timestampTz('finished_at')->nullable();

            $table->integer('attempt')->default(0);
            $table->boolean('retried')->default(false);
            $table->integer('progress')->nullable();

            $table->jsonb('exception')->nullable();
            $table->text('exception_class')->nullable();

            $table->text('data')->nullable();

            $table->timestampsTz();
        });
    }

    public function down()
    {
        Schema::drop(config('queue-monitor.table'));
    }
};
