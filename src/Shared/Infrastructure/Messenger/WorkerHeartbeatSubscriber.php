<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private bool $active = false;
    private int $lastWrite = 0;
    private ?float $queueAge = null;
    private ?int $lastDeliveryAt = null;

    public function __construct(private readonly MessengerWorkerHealth $health, private readonly ClockInterface $clock)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onStarted', WorkerRunningEvent::class => 'onRunning',
            WorkerMessageReceivedEvent::class => 'onReceived', WorkerStoppedEvent::class => 'onStopped',
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->active = in_array('async', $event->getWorker()->getMetadata()->getTransportNames(), true);
        if ($this->active) {
            $this->queueAge = null;
            $this->lastDeliveryAt = null;
            $this->health->record('starting');
            $this->lastWrite = 0;
        }
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->active && (!$event->isWorkerIdle() || $now - $this->lastWrite >= 10)) {
            // The event is emitted after polling/handling, so Redis was reachable.
            $this->health->record('idle', $this->queueAge, $this->lastDeliveryAt);
            $this->lastWrite = $now;
        }
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->active || $event->getReceiverName() !== 'async') {
            return;
        }
        $now = $this->clock->now();
        $id = $event->getEnvelope()->last(TransportMessageIdStamp::class)?->getId();
        $this->queueAge = is_string($id) && preg_match('/^(\d+)-\d+$/D', $id, $matches) === 1
            ? max(0.0, ((float) $now->format('U.u') * 1000 - (float) $matches[1]) / 1000)
            : null;
        $this->lastDeliveryAt = $now->getTimestamp();
        $this->health->record('busy', $this->queueAge, $this->lastDeliveryAt);
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        if ($this->active) {
            $this->health->record('stopped', $this->queueAge, $this->lastDeliveryAt);
            $this->active = false;
        }
    }
}
