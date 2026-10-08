<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

/**
 * The job the current execution is handling, so a cancellation checkpoint can find its job
 * ID without the handler knowing it.
 *
 * A Swoole task worker can run several jobs at once, one per coroutine, while the service is
 * shared, so the job ID is kept per coroutine. Outside a coroutine (a Messenger worker or a
 * console command) there is one key, -1. A coroutine that a job's handler starts does not
 * inherit the job.
 */
final class JobExecutionContext
{
    /** @var array<int, string> job IDs by coroutine ID */
    private array $jobIds = [];

    /**
     * Runs $work as the given job and restores the previous job of this coroutine afterwards.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(string $jobId, callable $work): mixed
    {
        $coroutineId = self::coroutineId();
        $previous = $this->jobIds[$coroutineId] ?? null;
        $this->jobIds[$coroutineId] = $jobId;

        try {
            return $work();
        } finally {
            if ($previous === null) {
                unset($this->jobIds[$coroutineId]);
            } else {
                $this->jobIds[$coroutineId] = $previous;
            }
        }
    }

    /** The job this coroutine is handling, or null outside a job run. */
    public function currentJobId(): ?string
    {
        return $this->jobIds[self::coroutineId()] ?? null;
    }

    private static function coroutineId(): int
    {
        return \extension_loaded('swoole') ? \Swoole\Coroutine::getCid() : -1;
    }
}
