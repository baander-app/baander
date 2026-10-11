<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Health\HealthMonitorSubscriber;
use App\Shared\Infrastructure\Swoole\WorkerMemoryReporter;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\CoroutineWatchdogWorkerStartHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears the Shared timers of a worker that stops because the server drains.
 *
 * Once Swoole has signalled a worker, the worker runs WorkerExit, again and again, until its
 * event loop is empty, and a timer left in it keeps the loop running until Swoole kills the
 * worker at max_wait_time. This subscriber clears the timers once, on the first WorkerExit.
 * QoL and Transcode stop their own timers and encoders from their own WorkerExit subscribers.
 *
 * A reload never sets the drain flag, so a reloading worker keeps its timers here; they are
 * cleared on WorkerStop as before.
 *
 * WorkerExit runs outside any coroutine; nothing here uses a pooled service.
 */
final class DrainWorkerExitSubscriber implements EventSubscriberInterface
{
    private bool $cleared = false;

    public function __construct(
        private readonly ServerDrainStateInterface $drain,
        private readonly WorkerDrain $workerDrain,
        private readonly HealthMonitorSubscriber $healthMonitor,
        private readonly WorkerMemoryReporter $memoryReporter,
        private readonly ?CoroutineWatchdogWorkerStartHandler $coroutineWatchdog = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerExitedEvent::NAME => 'onWorkerExited'];
    }

    public function onWorkerExited(WorkerExitedEvent $event): void
    {
        if ($this->cleared || !$this->drain->isDraining()) {
            return;
        }
        $this->cleared = true;

        $this->workerDrain->stop();
        $this->healthMonitor->stop();
        $this->memoryReporter->stop();
        $this->coroutineWatchdog?->stop();
    }
}
