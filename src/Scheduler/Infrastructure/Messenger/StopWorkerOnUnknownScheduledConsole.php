<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Messenger;

use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** Consumer exit asks the deployment supervisor to drain; it does not certify descendant containment. */
final class StopWorkerOnUnknownScheduledConsole implements EventSubscriberInterface
{
    private bool $stop = false;

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->stop = false;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $pending = [$event->getThrowable()];
        $seen = [];
        while ($pending !== []) {
            $error = array_pop($pending);
            $id = spl_object_id($error);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($error instanceof ScheduledConsoleCompletionUnknown) {
                $this->stop = true;
                return;
            }
            if ($error instanceof HandlerFailedException) {
                foreach ($error->getWrappedExceptions() as $wrapped) {
                    $pending[] = $wrapped;
                }
            }
            if ($error->getPrevious() !== null) {
                $pending[] = $error->getPrevious();
            }
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->stop) {
            $event->getWorker()->stop();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerMessageFailedEvent::class => ['onMessageFailed', 100],
            WorkerRunningEvent::class => ['onWorkerRunning', 100],
        ];
    }
}
