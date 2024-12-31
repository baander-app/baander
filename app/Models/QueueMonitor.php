<?php

namespace App\Models;

use App\Modules\QueueMonitor\MonitorStatus;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class QueueMonitor extends BaseModel
{
    protected $table = 'queue_monitor';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'failed'      => 'bool',
        'retried'     => 'bool',
        'queued_at'   => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'exception'   => 'json',
    ];

    // Scopes

    protected function scopeWhereJob(Builder $query, $jobId): void
    {
        $query->where('job_id', $jobId);
    }

    protected function scopeOrdered(Builder $query): void
    {
        $query
            ->orderBy('started_at', 'desc')
            ->orderBy('started_at_exact', 'desc');
    }

    protected function scopeLastHour(Builder $query): void
    {
        $query->where('started_at', '>', Carbon::now()->subHours(1));
    }

    protected function scopeToday(Builder $query): void
    {
        $query->whereRaw('DATE(started_at) = ?', [Carbon::now()->subHours(1)->format('Y-m-d')]);
    }

    protected function scopeFailed(Builder $query): void
    {
        $query->where('status', MonitorStatus::Failed);
    }

    protected function scopeSucceeded(Builder $query): void
    {
        $query->where('status', MonitorStatus::Succeeded);
    }

    // Methods

    public function getStartedAtExact(): ?Carbon
    {
        if (null === $this->started_at_exact) {
            return null;
        }

        return Carbon::parse($this->started_at_exact);
    }

    public function getFinishedAtExact(): ?Carbon
    {
        if (null === $this->finished_at_exact) {
            return null;
        }

        return Carbon::parse($this->finished_at_exact);
    }

    /**
     * Get the estimated remaining seconds. This requires a job progress to be set.
     *
     * @param Carbon|null $now
     *
     * @return float
     */
    public function getRemainingSeconds(?Carbon $now = null): float
    {
        return $this->getRemainingInterval($now)->totalSeconds;
    }

    public function getRemainingInterval(?Carbon $now = null): CarbonInterval
    {
        if (null === $now) {
            $now = Carbon::now();
        }

        if (!$this->progress || null === $this->started_at || $this->isFinished()) {
            return CarbonInterval::seconds(0);
        }

        if (0 === ($timeDiff = $now->getTimestamp() - $this->started_at->getTimestamp())) {
            return CarbonInterval::seconds(0);
        }

        return CarbonInterval::seconds(
            (100 - $this->progress) / ($this->progress / $timeDiff),
        )->cascade();
    }

    /**
     * Get the currently elapsed seconds.
     *
     * @param Carbon|null $end
     *
     * @return float
     */
    public function getElapsedSeconds(?Carbon $end = null): float
    {
        return $this->getElapsedInterval($end)->seconds;
    }

    public function getElapsedInterval(?Carbon $end = null): CarbonInterval
    {
        if (null === $end) {
            $end = $this->getFinishedAtExact() ?? $this->finished_at ?? Carbon::now();
        }

        $startedAt = $this->getStartedAtExact() ?? $this->started_at;

        if (null === $startedAt) {
            return CarbonInterval::seconds(0);
        }

        return $startedAt->diffAsCarbonInterval($end);
    }

    /**
     * Get any optional data that has been added to the monitor model within the job.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return json_decode($this->data, true) ?? [];
    }

    /**
     * Recreate the exception.
     *
     * @param bool $rescue Wrap the exception recreation to catch exceptions
     *
     * @return \Throwable|null
     */
    public function getException(bool $rescue = true): ?\Throwable
    {
        if (null === $this->exception_class) {
            return null;
        }

        if (!$rescue) {
            return new $this->exception_class($this->exception_message);
        }

        try {
            return new $this->exception_class($this->exception_message);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * Get the base class name of the job.
     *
     * @return string|null
     */
    public function getBasename(): ?string
    {
        if (null === $this->name) {
            return null;
        }

        return Arr::last(explode('\\', $this->name));
    }

    /**
     * check if the job is finished.
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        if ($this->hasFailed()) {
            return true;
        }

        return null !== $this->finished_at;
    }

    /**
     * Check if the job has failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return MonitorStatus::Failed === $this->status;
    }

    /**
     * check if the job has succeeded.
     *
     * @return bool
     */
    public function hasSucceeded(): bool
    {
        if (!$this->isFinished()) {
            return false;
        }

        return !$this->hasFailed();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function retry(): void
    {
        $this->retried = true;
        $this->save();

        $response = Artisan::call('queue:retry', ['id' => $this->job_uuid]);

        if (0 !== $response) {
            throw new \Exception(Artisan::output());
        }
    }

    public function canBeRetried(): bool
    {
        return !$this->retried
            && MonitorStatus::Failed === $this->status
            && null !== $this->job_uuid;
    }
}
