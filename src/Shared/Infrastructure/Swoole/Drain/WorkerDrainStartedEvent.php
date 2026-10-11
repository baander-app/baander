<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use Swoole\Server;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once in each event worker when it first sees the drain, while its client
 * connections still work. Listeners run in WorkerDrain's tick coroutine, which releases its
 * pooled services, so they may log and use repositories. They must not wait on task results or
 * pipe messages: Swoole stops delivering them once the workers are signalled.
 */
final class WorkerDrainStartedEvent extends Event
{
    public function __construct(
        private readonly Server $server,
        private readonly int $workerId,
    ) {
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getWorkerId(): int
    {
        return $this->workerId;
    }
}
