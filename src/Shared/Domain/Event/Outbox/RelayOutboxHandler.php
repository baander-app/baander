<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\DomainEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RelayOutboxHandler
{
    public function __construct(
        private readonly OutboxRepository $outboxRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RelayOutboxCommand $command): int
    {
        $pending = $this->outboxRepository->fetchPending($command->batchSize);
        $relayed = 0;

        foreach ($pending as $row) {
            try {
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);

                /** @var class-string<DomainEventInterface> $eventClass */
                $eventClass = $row['event_class'];

                if (method_exists($eventClass, 'fromPayload')) {
                    $event = $eventClass::fromPayload($payload);
                    // Dispatch with 'outbox.relay' event name to avoid re-triggering OutboxSubscriber
                    $this->eventDispatcher->dispatch($event, 'outbox.relay');
                }

                $this->outboxRepository->markRelayed((int) $row['id']);
                ++$relayed;
            } catch (\Throwable $e) {
                $this->logger->error('Outbox relay failed for event {event}', [
                    'event' => $row['event_name'],
                    'id' => $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $relayed;
    }
}
