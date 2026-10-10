<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Each HTTP worker writes its real memory use into WorkerMemoryTable when it starts
 * and then every WorkerMemoryTable::UPDATE_INTERVAL_SECONDS. Task workers report
 * nothing. The timer is cleared when the worker stops, so a reload leaves no stray
 * timer behind.
 *
 * CRITICAL: the timer callback uses no pooled service (no logger, entity manager or
 * connection), so it needs no CoWrapper::defer(). It reports problems with
 * error_log() only.
 */
final class WorkerMemoryReporter implements EventSubscriberInterface
{
    private ?int $timerId = null;
    private bool $refusalLogged = false;

    public function __construct(
        private readonly WorkerMemoryTable $table,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::NAME => 'onWorkerStarted',
            WorkerStoppedEvent::NAME => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($event->getServer()->taskworker) {
            return;
        }
        $workerId = $event->getWorkerId();

        $this->report($workerId);
        $timerId = Timer::tick(WorkerMemoryTable::UPDATE_INTERVAL_SECONDS * 1000, function () use ($workerId): void {
            $this->report($workerId);
        });
        // Native Swoole may return false; usable timer ids are positive.
        if ($timerId < 1) {
            error_log(sprintf('[WorkerMemoryReporter] Worker %d could not start its memory timer', $workerId));

            return;
        }
        $this->timerId = $timerId;
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
    }

    private function report(int $workerId): void
    {
        if ($this->table->record($workerId, memory_get_usage(true), time()) || $this->refusalLogged) {
            return;
        }

        $this->refusalLogged = true;
        error_log(sprintf('[WorkerMemoryReporter] Worker %d memory row was not stored; the memory check cannot see this worker', $workerId));
    }
}
