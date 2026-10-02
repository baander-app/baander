<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\AbstractDomainEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OutboxSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OutboxRepository $outboxRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Events are registered dynamically via OutboxSubscriberPass compiler pass.
        // This returns an empty array; the compiler pass adds the actual mappings.
        return [];
    }

    public function __invoke(AbstractDomainEvent $event): void
    {
        if (!method_exists($event, 'toPayload')) {
            return;
        }

        try {
            $payload = $event->toPayload();
        } catch (\Throwable $e) {
            $this->logger->warning('Outbox: failed to serialize event {event}', [
                'event' => $event->eventName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->outboxRepository->append(
            $event::class,
            $event->eventName(),
            $payload,
        );
    }
}
