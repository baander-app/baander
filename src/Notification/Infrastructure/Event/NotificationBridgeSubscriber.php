<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Event;

use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Domain\Service\EventCategoryResolver;
use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Infrastructure\Event\ReplayListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs notification projection synchronously inside an outbox replay transaction.
 *
 * Only forwards events that have a notification category mapping.
 * Unmapped events are silently dropped.
 */
final class NotificationBridgeSubscriber implements ReplayListenerProviderInterface
{
    public function __construct(
        private readonly EventCategoryResolver $categoryResolver,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function replayListeners(): iterable
    {
        foreach ($this->categoryResolver->getMappedEventClasses() as $eventClass) {
            yield [$eventClass, $this];
        }
    }

    public function __invoke(AbstractDomainEvent $event): void
    {
        $eventClass = $event::class;

        if (!$this->categoryResolver->resolve($eventClass)) {
            return;
        }

        if (!method_exists($event, 'toPayload')) {
            $this->logger->warning('Notification-mapped event {class} lacks toPayload().', [
                'class' => $eventClass,
                'event' => $event->eventName(),
            ]);

            throw new \UnexpectedValueException('Notification event lacks a payload contract.');
        }

        try {
            $payload = $event->toPayload();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to serialize event {event} for notification.', [
                'event' => $event->eventName(),
                'class' => $eventClass,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $command = new CreateNotificationCommand(
            eventClass: $eventClass,
            payload: $payload,
            eventName: $event->eventName(),
        );

        $this->bus->dispatch($command);
    }
}
