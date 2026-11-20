<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Application\Port\WorkerTimerInterface;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final class WorkerMemoryMaintenance implements EventSubscriberInterface, WorkerTimerInterface
{
    private ?int $timerId = null;
    private bool $ticking = false;

    public function __construct(
        private readonly ServicePoolContainer $pools,
        private readonly CoWrapper $coroutines,
        private readonly ServerDrainStateInterface $drain,
        private readonly int $intervalSeconds = 30,
        private readonly float $idleSeconds = 60.0,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::NAME => 'onWorkerStarted',
            WorkerExitedEvent::NAME => 'onWorkerExited',
            WorkerStoppedEvent::NAME => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($event->getServer()->taskworker || $this->timerId !== null) {
            return;
        }

        $timerId = Timer::tick($this->intervalSeconds * 1000, $this->tick(...));
        if ($timerId < 1) {
            error_log('[WorkerMemoryMaintenance] Could not start memory maintenance timer');

            return;
        }
        $this->timerId = $timerId;
    }

    public function onWorkerExited(WorkerExitedEvent $event): void
    {
        $this->stop();
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->stop();
    }

    public function stop(): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
    }

    public function tick(): void
    {
        if ($this->timerId === null || $this->ticking || $this->drain->isDraining()) {
            return;
        }

        // Destructors can yield and borrow services; run with the usual coroutine cleanup.
        $this->ticking = true;
        try {
            $this->coroutines->go(function (): void {
                try {
                    $this->pools->pruneFree($this->idleSeconds);
                    gc_collect_cycles();
                    gc_mem_caches();
                } catch (Throwable $error) {
                    $this->report($error);
                } finally {
                    $this->ticking = false;
                }
            });
        } catch (Throwable $error) {
            $this->ticking = false;
            $this->report($error);
        }
    }

    private function report(Throwable $error): void
    {
        error_log(sprintf('[WorkerMemoryMaintenance] %s: %s', $error::class, $error->getMessage()));
    }
}
