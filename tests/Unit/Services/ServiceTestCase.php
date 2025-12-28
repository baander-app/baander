<?php

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class ServiceTestCase extends TestCase
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
     * Assert that cache has a specific key
     *
     * @param string $key
     * @return void
     */
    protected function assertCacheHas(string $key): void
    {
        $this->assertTrue(Cache::has($key), "Cache should have key: {$key}");
    }

    /**
     * Assert that cache does not have a specific key
     *
     * @param string $key
     * @return void
     */
    protected function assertCacheMissing(string $key): void
    {
        $this->assertFalse(Cache::has($key), "Cache should not have key: {$key}");
    }
}
