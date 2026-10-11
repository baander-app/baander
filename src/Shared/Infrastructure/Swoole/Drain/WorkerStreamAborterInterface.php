<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

/**
 * Cuts the streamed responses an event worker is still sending when the drain window is about to
 * end, so each client sees a transfer error and the worker can report itself drained.
 *
 * WorkerDrain calls abortAll() on every tick from WorkerDrain::STREAM_CUT_LEAD_MILLISECONDS before
 * the window ends until the worker is idle. The first call cuts every stream; later calls only
 * wake the coroutines of streams that have not stopped yet. Without an implementation a worker
 * that is still streaming never reports drained: the master's window then ends on its own and
 * resets the connection.
 */
interface WorkerStreamAborterInterface
{
    public function abortAll(): void;
}
