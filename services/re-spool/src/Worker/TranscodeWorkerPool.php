<?php

namespace Baander\ReSpool\Worker;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use Psr\Log\LoggerInterface;

class TranscodeWorkerPool implements WorkerPool
{
    use ForbidCloning, ForbidSerialization;

    private readonly \SplObjectStorage $workers;
    private readonly \SplQueue $idleWorkers;
    private readonly \SplQueue $waiting;
    private readonly \Closure $push;

    private ?Future $exitCode = null;

    protected readonly DeferredCancellation $deferredCancellation;

    public function __construct(
        private readonly LoggerInterface $logger,
        protected array $workerConfigStack
    )
    {
        $this->workers = new \SplObjectStorage();
        $this->idleWorkers = $idleWorkers = new \SplQueue();
        $this->waiting = $waiting = new \SplQueue();
        $this->deferredCancellation = new DeferredCancellation();

        foreach ($this->workerConfigStack as $workerId => $workerConfig) {
            $worker = new TaskWorker($this->logger, $workerConfig);
            $this->workers->attach($worker, $workerId);
            $this->idleWorkers->enqueue($worker);
            $this->logger->info("New worker registered successfully: {$worker->config->id}");
        }

        $this->push = function (TaskWorker $worker) use ($waiting, $idleWorkers): void {
            if (!$worker->isRunning()) {
                $this->logger->debug(
                    "Ignoring push of worker {$worker->config->id} back into the pool (not running)"
                );
                return;
            }
            if ($waiting->isEmpty()) {
                $idleWorkers->push($worker);
            } else {
                $waiting->dequeue()->complete($worker);
            }
        };
    }

    public function isRunning(): bool
    {
        return !$this->deferredCancellation->isCancelled();
    }

    public function isIdle(): bool
    {
        return $this->idleWorkers->count() > 0 || $this->workers->count() < $this->getLimit();
    }

    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        // TODO: Implement submit() method.
    }

    public function shutdown(): void
    {
        // TODO: Implement shutdown() method.
    }

    public function kill(): void
    {
        // TODO: Implement kill() method.
    }

    public function getWorker(): Worker
    {
        // TODO: Implement getWorker() method.
    }

    public function getWorkerCount(): int
    {
        // TODO: Implement getWorkerCount() method.
    }

    public function getIdleWorkerCount(): int
    {
        // TODO: Implement getIdleWorkerCount() method.
    }
}