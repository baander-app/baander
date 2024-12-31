<?php

namespace Baander\ReSpool\Worker;

use Amp\Cancellation;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Psr\Log\LoggerInterface;

class TaskWorker implements Worker
{
    protected Worker $worker;

    public function __construct(
        protected readonly LoggerInterface $logger,
        public readonly array              $taskData,
        ?Worker                            $worker = null,
    )
    {
        $this->worker = $worker ?? \Amp\Parallel\Worker\createWorker();
    }

    public function isRunning(): bool
    {
        return $this->worker->isRunning();
    }

    public function isIdle(): bool
    {
        return $this->worker->isIdle();
    }

    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        return $this->worker->submit($task, $cancellation);
    }

    public function shutdown(): void
    {
        $this->worker->shutdown();
    }

    public function kill(): void
    {
        $this->worker->kill();
    }
}