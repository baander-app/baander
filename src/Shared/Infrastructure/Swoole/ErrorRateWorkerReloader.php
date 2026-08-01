<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Psr\Log\LoggerInterface;
use Swoole\Server;
use Throwable;

/**
 * Tracks 5xx errors per Swoole worker and triggers a graceful worker reload
 * when the consecutive error threshold is exceeded.
 *
 * Swoole's built-in worker_max_request counter resets on every response,
 * including error responses. A worker stuck in a bad state (corrupted EM,
 * stale connections, etc.) can serve hundreds of 500s without ever recycling.
 *
 * This monitors consecutive 5xx responses per worker and forces a reload
 * via Server::reload() when the threshold is hit, breaking the error spiral.
 */
final class ErrorRateWorkerReloader
{
    /** @var array<int, int> workerId => consecutive 5xx count */
    private array $consecutiveErrors = [];

    /** @var array<int, float> workerId => timestamp of last reload trigger */
    private array $lastReloadAt = [];

    private ?Server $server = null;

    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 30,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * Called after a successful 2xx/3xx/4xx response resets the counter.
     */
    public function recordSuccess(int $workerId): void
    {
        $this->consecutiveErrors[$workerId] = 0;
    }

    /**
     * Called when a 5xx response is produced. If consecutive 5xx count
     * exceeds the threshold, triggers a graceful worker reload.
     */
    public function recordError(int $workerId, Throwable $exception): void
    {
        $count = ($this->consecutiveErrors[$workerId] ?? 0) + 1;
        $this->consecutiveErrors[$workerId] = $count;

        if ($count < $this->threshold) {
            return;
        }

        $now = microtime(true);
        $lastReload = $this->lastReloadAt[$workerId] ?? 0;

        // Cooldown: don't spam reloads for the same worker
        if ($now - $lastReload < $this->cooldownSeconds) {
            $this->logger?->debug('Worker error threshold exceeded but cooldown active', [
                'workerId' => $workerId,
                'consecutiveErrors' => $count,
                'cooldownRemaining' => round($this->cooldownSeconds - ($now - $lastReload), 1),
            ]);
            return;
        }

        if ($this->server === null) {
            $this->logger?->warning('Cannot reload worker: Swoole server instance not set', [
                'workerId' => $workerId,
            ]);
            return;
        }

        $this->lastReloadAt[$workerId] = $now;
        $this->consecutiveErrors[$workerId] = 0;

        $this->logger?->error('Reloading worker due to consecutive 5xx errors', [
            'workerId' => $workerId,
            'consecutiveErrors' => $count,
            'exceptionClass' => $exception::class,
            'exceptionMessage' => $exception->getMessage(),
        ]);

        // Server::reload() with SWOOLE_GRACEFUL_RELOAD_FLAG sends SIGUSR1 to manager,
        // which gracefully restarts all workers (finishes in-flight requests first).
        // This is the same mechanism worker_max_request uses internally.
        $this->server->reload();
    }
}
