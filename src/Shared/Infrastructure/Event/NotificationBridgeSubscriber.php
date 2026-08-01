<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Domain\Service\EventCategoryResolver;
use App\Shared\Domain\Event\AbstractDomainEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bridge subscriber that forwards synchronous domain events into the
 * async Messenger pipeline for notification processing.
 *
 * Only forwards events that have a notification category mapping.
 * Unmapped events are silently dropped.
 */
final class NotificationBridgeSubscriber
{
    public function __construct(
        private readonly EventCategoryResolver $categoryResolver,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AbstractDomainEvent $event): void
    {
        $eventClass = $event::class;

        if (!$this->categoryResolver->resolve($eventClass)) {
            return;
        }

        if (!method_exists($event, 'toPayload')) {
            $this->logger->warning('Notification-mapped event {class} lacks toPayload(), skipping.', [
                'class' => $eventClass,
                'event' => $event->eventName(),
            ]);

            return;
        }

        try {
            $payload = $event->toPayload();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to serialize event {event} for notification.', [
                'event' => $event->eventName(),
                'class' => $eventClass,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $command = new CreateNotificationCommand(
            eventClass: $eventClass,
            payload: $payload,
            eventName: $event->eventName(),
        );

        $this->bus->dispatch($command);
    }
}
