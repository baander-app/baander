<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\WorkerHandler;

use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Coroutine\Watchdog\CoroutineWatchdog;

/**
 * Starts the coroutine watchdog timer on WorkerStart.
 * Follows the same pattern as HMRWorkerStartHandler — registers a tick()
 * timer that periodically checks for stalled coroutines.
 *
 * Skips task workers (they don't run request coroutines).
 * The watchdog is a no-op on idle workers (Coroutine::list() returns empty).
 *
 * The timer keeps the worker's event loop running, so a worker that should exit
 * promptly calls stop() from WorkerExit.
 */
final class CoroutineWatchdogWorkerStartHandler implements WorkerStartHandler
{
    private ?int $timerId = null;

    public function __construct(
        private readonly CoroutineWatchdog $watchdog,
        private readonly Swoole $swoole,
        private readonly int $intervalMs = 5000,
        private readonly ?WorkerStartHandler $decorated = null,
    ) {}

    public function handle(Server $worker, int $workerId): void
    {
        if ($this->decorated instanceof WorkerStartHandler) {
            $this->decorated->handle($worker, $workerId);
        }

        if ($worker->taskworker) {
            return;
        }

        $timerId = $this->swoole->tick($this->intervalMs, function (): void {
            $this->watchdog->check();
        });
        // Native Swoole may return false; usable timer ids are positive.
        $this->timerId = is_int($timerId) && $timerId > 0 ? $timerId : null;
    }

    /** Clears the watchdog timer. Safe to call more than once. */
    public function stop(): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
    }
}
