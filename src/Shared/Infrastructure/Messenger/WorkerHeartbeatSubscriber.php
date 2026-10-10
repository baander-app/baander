<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleAlarmEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Throwable;

/**
 * Publishes the durable consumer's heartbeat. Messenger emits no event while a handler
 * runs, so a busy heartbeat is refreshed from the console alarm that `--keepalive`
 * schedules for the transport keepalive.
 */
final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private const array DURABLE_TRANSPORTS = ['async', 'scheduler'];

    private bool $active = false;
    private bool $busy = false;
    private int $lastWrite = 0;
    private ?float $queueAge = null;
    private ?int $lastDeliveryAt = null;

    public function __construct(
        private readonly MessengerWorkerHealth $health,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onStarted', WorkerRunningEvent::class => 'onRunning',
            WorkerMessageReceivedEvent::class => 'onReceived', WorkerStoppedEvent::class => 'onStopped',
            ConsoleAlarmEvent::class => 'onAlarm',
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->active = array_intersect(self::DURABLE_TRANSPORTS, $event->getWorker()->getMetadata()->getTransportNames()) !== [];
        if ($this->active) {
            $this->busy = false;
            $this->queueAge = null;
            $this->lastDeliveryAt = null;
            $this->publish('starting');
            $this->lastWrite = 0;
        }
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->active && (!$event->isWorkerIdle() || $now - $this->lastWrite >= 10)) {
            // The event is emitted after polling/handling, so Redis was reachable.
            $this->busy = false;
            $this->publish('idle');
            $this->lastWrite = $now;
        }
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->active || !in_array($event->getReceiverName(), self::DURABLE_TRANSPORTS, true)) {
            return;
        }
        $now = $this->clock->now();
        $id = $event->getEnvelope()->last(TransportMessageIdStamp::class)?->getId();
        $this->queueAge = is_string($id) && preg_match('/^(\d+)-\d+$/D', $id, $matches) === 1
            ? max(0.0, ((float) $now->format('U.u') * 1000 - (float) $matches[1]) / 1000)
            : null;
        $this->lastDeliveryAt = $now->getTimestamp();
        $this->busy = true;
        $this->publish('busy');
    }

    public function onAlarm(ConsoleAlarmEvent $event): void
    {
        if ($this->active && $this->busy) {
            $this->publish('busy');
        }
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        if ($this->active) {
            $this->publish('stopped');
            $this->active = false;
            $this->busy = false;
        }
    }

    private function publish(string $phase): void
    {
        try {
            $this->health->record($phase, $this->queueAge, $this->lastDeliveryAt);
        } catch (Throwable $e) {
            // A missed heartbeat must never stop the consumer; the web server sees it go stale.
            $this->logger->warning('Cannot publish the Messenger worker heartbeat.', ['phase' => $phase, 'exception' => $e]);
        }
    }
}
