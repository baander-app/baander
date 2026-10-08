<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use Swoole\Server;

/**
 * Holds the server attached by ControlSocketConfigurator before start; workers
 * inherit it on fork. A console process never configures a server, so it has none.
 */
final class SwooleServerWorkers implements ServerWorkers
{
    private ?Server $server = null;

    public function attach(Server $server): void
    {
        $this->server = $server;
    }

    public function currentHttpWorkerId(): ?int
    {
        $server = $this->server;
        // Before start, in the master and manager, in task workers, and in processes
        // forked from a worker (CPU pool children inherit the server object), the
        // control channel is not reachable in-process.
        if ($server === null || $server->taskworker || $server->worker_pid !== getmypid()) {
            return null;
        }
        $workerId = $server->worker_id;

        return $workerId >= 0 && $workerId < $this->workerCount($server) ? $workerId : null;
    }

    public function httpWorkerIds(): array
    {
        return $this->server === null ? [] : range(0, $this->workerCount($this->server) - 1);
    }

    public function send(array $message, int $workerId): bool
    {
        return $this->server !== null && $this->server->sendMessage($message, $workerId);
    }

    private function workerCount(Server $server): int
    {
        $setting = $server->setting;

        return is_array($setting) && is_int($setting['worker_num'] ?? null) ? $setting['worker_num'] : 0;
    }
}
