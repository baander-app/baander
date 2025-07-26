<?php

namespace App\Modules\Queue\QueueMonitor;

use App\Models\QueueMonitor;
use App\Modules\Queue\QueueMonitor\Concerns\IsMonitored;
use App\Modules\Queue\QueueMonitor\Contracts\MonitoredJobContract;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\{JobExceptionOccurred, JobFailed, JobProcessed, JobProcessing, JobQueued};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Crypt};
use JsonException;
use Laravel\Horizon\Events\JobPushed;
use Throwable;

class QueueMonitorService
{
    private const string TIMESTAMP_EXACT_FORMAT = 'Y-m-d H:i:s.u';


    public function handleJobQueued(JobQueued $event): void
    {
        self::jobQueued($event);
    }

    public function handleJobPushed(JobPushed $event): void
    {
        self::jobPushed($event);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        self::jobStarted($event->job);
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        self::jobFinished($event->job, MonitorStatus::Succeeded);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        self::jobFinished($event->job, MonitorStatus::Failed, $event->exception);
    }

    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        self::jobFinished($event->job, MonitorStatus::Failed, $event->exception);
    }

    public function getJobId(JobContract $job): string
    {
        if ($jobId = $job->getJobId()) {
            return (string)$jobId;
        }

        return sha1($job->getRawBody());
    }

    /**
     * Start Queue Monitoring for Job.
     *
     * @param JobQueued $event
     *
     * @return void
     * @throws JsonException
     */
    protected function jobQueued(JobQueued $event): void
    {
        if (!self::shouldBeMonitored($event->job)) {
            return;
        }

        // add initial data
        if (method_exists($event->job, 'initialMonitorData')) {
            $data = json_encode($event->job->initialMonitorData());
        }

        QueueMonitor::create([
            'job_id'    => $event->id,
            /** @phpstan-ignore-next-line */
            'job_uuid'  => isset($event->payload) ? $event->payload()['uuid'] : (is_numeric($event->id) ? null : $event->id),
            'name'      => get_class($event->job),
            /** @phpstan-ignore-next-line */
            'queue'     => $event->job->queue ?: 'default',
            'status'    => MonitorStatus::Queued,
            'queued_at' => now(),
            'data'      => $data ?? null,
        ]);
    }

    /**
     * Start Queue Monitoring for Job.
     *
     * @param JobPushed $event
     *
     * @return void
     */
    protected function jobPushed($event): void
    {
        if (!self::shouldBeMonitored($event->payload->displayName())) {
            return;
        }

        $initialData = null;

        // add initial data
        if (method_exists($event->payload->displayName(), 'initialMonitorData')) {
            $jobInstance = self::getJobInstance($event->payload->decoded['data']);
            $initialData = $jobInstance->initialMonitorData();
        }

        QueueMonitor::create([
            'job_id'    => $event->payload->decoded['id'] ?? $event->payload->decoded['uuid'],
            'job_uuid'  => $event->payload->decoded['uuid'] ?? null,
            'name'      => $event->payload->displayName(),
            'queue'     => $event->queue ?: 'default',
            'status'    => MonitorStatus::Queued,
            'queued_at' => now(),
            'data'      => $initialData ? json_encode($initialData) : null,
        ]);

        // mark the retried job
        if ($event->payload->isRetry()) {
            QueueMonitor::where('job_uuid', $event->payload->retryOf())->update(['retried' => true]);
        }
    }

    /**
     * Job Start Processing.
     *
     * @param JobContract $job
     *
     * @return void
     */
    protected function jobStarted(JobContract $job): void
    {
        if (!self::shouldBeMonitored($job)) {
            return;
        }

        $now = Carbon::now();

        $monitor = QueueMonitor::updateOrCreate([
            'job_id' => $jobId = self::getJobId($job),
            'queue'  => $job->getQueue() ?: 'default',
            'status' => MonitorStatus::Queued,
        ], [
            'job_uuid'         => $job->uuid(),
            'name'             => $job->resolveName(),
            'started_at'       => $now,
            'started_at_exact' => $now->format(self::TIMESTAMP_EXACT_FORMAT),
            'attempt'          => $job->attempts(),
            'status'           => MonitorStatus::Running,
        ]);

        // Mark jobs with same job id (different execution) as stale
        QueueMonitor::where('id', '!=', $monitor->id)
            ->where('job_id', $jobId)
            ->where('status', '!=', MonitorStatus::Failed)
            ->whereNull('finished_at')
            ->each(function (QueueMonitor $monitor) {
                $monitor->update([
                    'finished_at'       => $now = Carbon::now(),
                    'finished_at_exact' => $now->format(self::TIMESTAMP_EXACT_FORMAT),
                    'status'            => MonitorStatus::Stale,
                ]);
            });
    }

    /**
     * Finish Queue Monitoring for Job.
     *
     * @param JobContract $job
     * @param MonitorStatus $status
     * @param Throwable|null $exception
     *
     * @return void
     */
    protected function jobFinished(JobContract $job, MonitorStatus $status, ?Throwable $exception = null): void
    {
        if (!self::shouldBeMonitored($job)) {
            return;
        }

        $monitor = QueueMonitor::where('job_id', self::getJobId($job))
            ->where('attempt', $job->attempts())
            ->orderByDesc('started_at')
            ->first();

        if (null === $monitor) {
            return;
        }

        $now = Carbon::now();

        $resolvedJob = $job->resolveName();

        if (null === $exception && false === $resolvedJob::keepMonitorOnSuccess()) {
            $monitor->delete();

            return;
        }

        // if the job has an exception, but it's not failed (it did not exceed max tries and max exceptions),
        // so it will be back to the queue
        if (MonitorStatus::Failed == $status && !$job->hasFailed()) {
            $status = MonitorStatus::Queued;
        }

        // if the job is processed, but it's released, so it will be back to the queue also
        if (MonitorStatus::Stale == $status && $job->isReleased()) {
            $status = MonitorStatus::Queued;
        }

        $attributes = [
            'finished_at'       => $now,
            'finished_at_exact' => $now->format(self::TIMESTAMP_EXACT_FORMAT),
            'status'            => $status,
        ];

        if (null !== $exception) {
            $attributes += [
                'exception'       => $exception,
                'exception_class' => get_class($exception),
            ];
        }

        $monitor->update($attributes);
    }

    /**
     * Determine weather the Job should be monitored, default true.
     *
     * @param object|string|MonitoredJobContract $job
     * @return bool
     */
    public function shouldBeMonitored(object|string $job): bool
    {
        $class = $job instanceof JobContract ? $job->resolveName() : $job;

        return array_key_exists(IsMonitored::class, class_uses_recursive($class));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return MonitoredJobContract
     */
    private function getJobInstance(array $data)
    {
        if (str_starts_with($data['command'], 'O:')) {
            return unserialize($data['command']);
        }

        return Crypt::decrypt($data['command']);
    }
}