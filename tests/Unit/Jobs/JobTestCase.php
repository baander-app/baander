<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

abstract class JobTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::flush();
    }

    /**
     * Assert that a job was pushed for a specific model
     *
     * @param string $jobClass
     * @param mixed $model
     * @param int $times
     * @return void
     */
    protected function assertJobPushedFor(string $jobClass, $model, int $times = 1): void
    {
        Queue::assertPushed($jobClass, $times, function ($job) use ($model) {
            return $job->getTargetId() === $model->id
                || (property_exists($job, 'model') && $job->model->id === $model->id)
                || (property_exists($job, $model->getForeignKey()) && $job->{$model->getForeignKey()}->id === $model->id);
        });
    }

    /**
     * Assert that a job was not pushed for a specific model
     *
     * @param string $jobClass
     * @param mixed $model
     * @return void
     */
    protected function assertJobNotPushedFor(string $jobClass, $model): void
    {
        Queue::assertNotPushed($jobClass, function ($job) use ($model) {
            return $job->getTargetId() === $model->id
                || (property_exists($job, 'model') && $job->model->id === $model->id)
                || (property_exists($job, $model->getForeignKey()) && $job->{$model->getForeignKey()}->id === $model->id);
        });
    }

    /**
     * Get the total number of queued jobs
     *
     * @return int
     */
    protected function getQueuedJobsCount(): int
    {
        return count(Queue::pushedJobs());
    }

    /**
     * Assert that a specific number of jobs were pushed
     *
     * @param int $count
     * @return void
     */
    protected function assertJobCount(int $count): void
    {
        $this->assertCount($count, Queue::pushedJobs());
    }
}
