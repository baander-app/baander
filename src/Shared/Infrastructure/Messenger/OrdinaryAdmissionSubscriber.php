<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Transport\TransportInterface;

final readonly class OrdinaryAdmissionSubscriber implements EventSubscriberInterface
{
    public function __construct(private TransportInterface $transport)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageReceivedEvent::class => ['received', 1024],
            WorkerMessageHandledEvent::class => ['handled', -1024], WorkerMessageFailedEvent::class => ['handled', -1024]];
    }

    public function received(WorkerMessageReceivedEvent $event): void
    {
        if ($event->getReceiverName() === 'async' && $this->transport instanceof OrdinaryAdmissionTransport) {
            $this->transport->received($event->getEnvelope());
        }
    }

    public function handled(WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void
    {
        if ($event->getReceiverName() === 'async' && $this->transport instanceof OrdinaryAdmissionTransport) {
            $this->transport->handled($event->getEnvelope());
        }
    }
}
