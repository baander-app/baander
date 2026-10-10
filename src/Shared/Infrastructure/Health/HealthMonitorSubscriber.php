<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Psr\Log\LoggerInterface;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs the health monitor in HTTP worker 0 of the web server: a Swoole timer calls
 * HealthAlertService::checkAndAlert() every interval, the first time one interval
 * after the worker starts, so services starting alongside the server do not alert.
 * Task workers and the other HTTP workers start nothing. The timer is cleared when
 * the worker stops, so an old and a new worker 0 never tick together across a reload.
 *
 * Each tick runs in a coroutine the swoole bundle does not manage and uses the
 * database connection, the entity manager and the logger, so it releases its pooled
 * services first (CoWrapper::defer()). A tick that finds this worker's previous tick
 * still running logs a warning and returns, so a hung check leaves a trace.
 */
final class HealthMonitorSubscriber implements EventSubscriberInterface
{
    private ?int $timerId = null;
    private bool $ticking = false;

    /** The container passes the CoWrapper; it is optional only for tests. */
    public function __construct(
        private readonly HealthAlertService $alerts,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
        private readonly ?CoWrapper $coWrapper = null,
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
        if ($event->getServer()->taskworker || $event->getWorkerId() !== 0 || $this->timerId !== null) {
            return;
        }

        $timerId = Timer::tick($this->intervalSeconds * 1000, function (): void {
            $this->tick();
        });
        // Native Swoole may return false; usable timer ids are positive.
        if ($timerId < 1) {
            error_log('[HealthMonitorSubscriber] Worker 0 could not start the health monitor timer');

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

    /** One monitor run; public for the timer callback and tests. */
    public function tick(): void
    {
        $this->coWrapper?->defer();
        if ($this->ticking) {
            $this->logger->warning('Health monitor tick skipped: the previous tick is still running.');

            return;
        }

        $this->ticking = true;
        try {
            $this->alerts->checkAndAlert();
        } catch (\Throwable $e) {
            $this->logger->error('Health monitor check failed; the next tick runs it again.', ['exception' => $e]);
        } finally {
            $this->ticking = false;
        }
    }
}
