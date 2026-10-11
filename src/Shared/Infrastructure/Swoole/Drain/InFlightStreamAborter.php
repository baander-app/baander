<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\InFlightStreams;

/**
 * Cuts the streamed responses the bundle's StreamedResponseProcessor is sending in this worker:
 * each connection is reset without the response's end, and each writing coroutine's wait is
 * cancelled, so it stops at its next Async::sleep(), which throws CoroutineCanceledException.
 */
final readonly class InFlightStreamAborter implements WorkerStreamAborterInterface
{
    public function __construct(private InFlightStreams $streams)
    {
    }

    public function abortAll(): void
    {
        $this->streams->abortAll();
    }
}
