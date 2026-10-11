<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

/**
 * Cuts the streamed responses an event worker is still sending when the drain window ends, so
 * each client sees a transfer error and the worker can report itself drained.
 *
 * WorkerDrain calls abortAll() once, from its tick, when the window ends. Without an
 * implementation a worker that is still streaming never reports drained: the master's window
 * then ends on its own at its end and resets the connection.
 */
interface WorkerStreamAborterInterface
{
    public function abortAll(): void;
}
