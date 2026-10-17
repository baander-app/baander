<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Worker;

use App\QoL\Infrastructure\Swoole\AlgorithmProfileTable;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;

/** Owns QoL state and timers for one admitted video child. */
final readonly class WorkerQoLLifecycle
{
    public function __construct(
        private AlgorithmProfileTable $profiles,
        private LearningDataPersister $learning,
        private CpuGpuSampler $sampler,
        private MidStreamMonitor $monitor,
    ) {
    }

    public function start(): void
    {
        $this->profiles->boot();
        $this->learning->restoreForWorker();
        try {
            $this->sampler->startWorkerSampling();
            $this->monitor->startMonitoring();
        } catch (\Throwable $exception) {
            $this->monitor->stopMonitoring();
            $this->sampler->stopSampling();
            throw $exception;
        }
    }

    public function stop(): void
    {
        $this->monitor->stopMonitoring();
        $this->sampler->stopSampling();
        $this->learning->save();
    }
}
