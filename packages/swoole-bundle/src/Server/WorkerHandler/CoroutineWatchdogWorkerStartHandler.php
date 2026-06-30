<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\WorkerHandler;

use Swoole\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Coroutine\Watchdog\CoroutineWatchdog;

/**
 * Starts the coroutine watchdog timer on WorkerStart.
 * Follows the same pattern as HMRWorkerStartHandler — registers a tick()
 * timer that periodically checks for stalled coroutines.
 *
 * Skips task workers (they don't run request coroutines).
 * The watchdog is a no-op on idle workers (Coroutine::list() returns empty).
 */
final readonly class CoroutineWatchdogWorkerStartHandler implements WorkerStartHandler
{
    public function __construct(
        private CoroutineWatchdog $watchdog,
        private Swoole $swoole,
        private int $intervalMs = 5000,
        private ?WorkerStartHandler $decorated = null,
    ) {}

    public function handle(Server $worker, int $workerId): void
    {
        if ($this->decorated instanceof WorkerStartHandler) {
            $this->decorated->handle($worker, $workerId);
        }

        if ($worker->taskworker) {
            return;
        }

        $this->swoole->tick($this->intervalMs, function (): void {
            $this->watchdog->check();
        });
    }
}
