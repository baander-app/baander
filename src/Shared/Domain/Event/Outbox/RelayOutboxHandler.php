<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\DomainEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RelayOutboxHandler
{
    private const int MAX_ATTEMPTS = 5;
    private const int BASE_BACKOFF_SECONDS = 2;

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
        $failures = [];

        foreach ($pending as $row) {
            $id = (int) $row['id'];

            try {
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);

                /** @var class-string<DomainEventInterface> $eventClass */
                $eventClass = $row['event_class'];

                if (method_exists($eventClass, 'fromPayload')) {
                    $event = $eventClass::fromPayload($payload);
                    // Dispatch with 'outbox.relay' event name to avoid re-triggering OutboxSubscriber
                    $this->eventDispatcher->dispatch($event, 'outbox.relay');
                }

                $this->outboxRepository->markRelayed($id);
                ++$relayed;
            } catch (\Throwable $e) {
                $attempts = ((int) ($row['attempts'] ?? 0)) + 1;

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->outboxRepository->recordFailure($id, $attempts, null, new \DateTimeImmutable());
                    $this->logger->error('Outbox event moved to dead-letter after {attempts} attempts', [
                        'id' => $id,
                        'event' => $row['event_name'],
                        'attempts' => $attempts,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $nextAttemptAt = (new \DateTimeImmutable())->modify(
                        sprintf('+%d seconds', self::BASE_BACKOFF_SECONDS * (2 ** ($attempts - 1))),
                    );

                    $this->outboxRepository->recordFailure($id, $attempts, $nextAttemptAt, null);
                    $this->logger->warning('Outbox relay failed for event {event}; will retry', [
                        'id' => $id,
                        'event' => $row['event_name'],
                        'attempts' => $attempts,
                        'next_attempt_at' => $nextAttemptAt->format(\DateTimeImmutable::ATOM),
                        'error' => $e->getMessage(),
                    ]);
                }

                $failures[] = [
                    'id' => $id,
                    'event' => $row['event_name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($failures !== []) {
            throw new OutboxRelayException(
                sprintf('Outbox relay failed for %d event(s).', count($failures)),
                $failures,
            );
        }

        return $relayed;
    }
}
