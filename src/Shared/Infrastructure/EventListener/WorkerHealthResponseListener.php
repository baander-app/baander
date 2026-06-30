<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\Swoole\ErrorRateWorkerReloader;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Resets the consecutive error counter on successful responses.
 * Paired with ExceptionSubscriber which increments it on 5xx errors.
 */
#[AsEventListener(event: 'kernel.response', priority: -999)]
final class WorkerHealthResponseListener
{
    public function __construct(
        private readonly ErrorRateWorkerReloader $errorRateReloader,
    ) {}

    public function __invoke(ResponseEvent $event): void
    {
        if (!\function_exists('swoole_get_worker_id')) {
            return;
        }

        $workerId = \swoole_get_worker_id();
        if ($workerId < 0) {
            return;
        }

        // Only reset on non-5xx — 5xx errors are tracked in ExceptionSubscriber
        // and handled before this listener fires.
        $status = $event->getResponse()->getStatusCode();
        if ($status < 500) {
            $this->errorRateReloader->recordSuccess($workerId);
        }
    }
}
