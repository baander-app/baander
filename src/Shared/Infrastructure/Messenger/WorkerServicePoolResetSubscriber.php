<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/** Releases pooled services between CLI deliveries, as the HTTP/task runtime does. */
final readonly class WorkerServicePoolResetSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ServicePoolContainer $pools,
        private Swoole $swoole,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Retry/failure dispatch has finished. Release pooled services before
            // Symfony resets the remaining non-pooled services at priority -1024.
            WorkerRunningEvent::class => ['onRunning', -1000],
            WorkerStoppedEvent::class => ['onStopped', -1000],
        ];
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle()) {
            $this->pools->releaseFromCoroutine($this->swoole->getCoroutineId());
        }
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        $this->pools->releaseFromCoroutine($this->swoole->getCoroutineId());
    }
}
