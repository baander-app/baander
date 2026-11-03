<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Worker;

use App\QoL\Infrastructure\Swoole\AlgorithmProfileTable;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;

/** Owns QoL state and the mid-stream monitor for one admitted video encode child. */
final readonly class WorkerQoLLifecycle
{
    public function __construct(
        private AlgorithmProfileTable $profiles,
        private LearningDataPersister $learning,
        private MidStreamMonitor $monitor,
    ) {
    }

    public function start(): void
    {
        $this->profiles->boot();
        $this->learning->restoreForWorker();
        $this->monitor->startMonitoring();
    }

    public function stop(): void
    {
        $this->monitor->stopMonitoring();
        $this->learning->save();
    }
}
