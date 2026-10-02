<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\DomainEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RelayOutboxHandler
{
    private const int MAX_ATTEMPTS = 5;
    private const int BASE_BACKOFF_SECONDS = 2;

    public function __construct(
        private readonly OutboxRepository $outboxRepository,
        private readonly OutboxEventDispatcherInterface $eventDispatcher,
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
            $leaseToken = $row['lease_token'];

            if (!$this->outboxRepository->renewLease($id, $leaseToken)) {
                continue;
            }

            try {
                $decodedPayload = json_decode($row['payload'], false, 512, JSON_THROW_ON_ERROR);
                if (!$decodedPayload instanceof \stdClass) {
                    throw new \UnexpectedValueException('Outbox payload must be a JSON object.');
                }
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);

                /** @var class-string<DomainEventInterface> $eventClass */
                $eventClass = $row['event_class'];

                if (!class_exists($eventClass) || !is_subclass_of($eventClass, DomainEventInterface::class)
                    || (new \ReflectionClass($eventClass))->isAbstract()
                    || !method_exists($eventClass, 'fromPayload')) {
                    throw new \UnexpectedValueException(sprintf('Unsupported outbox event class: %s.', $eventClass));
                }
                $factory = new \ReflectionMethod($eventClass, 'fromPayload');
                if (!$factory->isPublic() || !$factory->isStatic()) {
                    throw new \UnexpectedValueException('Outbox event factory must be public and static.');
                }

                $event = $eventClass::fromPayload($payload);
                if (!$event instanceof DomainEventInterface || $event::class !== $eventClass
                    || $event->eventName() !== $row['event_name']) {
                    throw new \UnexpectedValueException('Reconstructed outbox event does not match its stored class and name.');
                }
                $this->eventDispatcher->dispatch($event, $id);

                if (!$this->outboxRepository->markRelayed($id, $leaseToken)) {
                    throw new \RuntimeException('Outbox lease expired before acknowledgement.');
                }
                ++$relayed;
            } catch (\Throwable $e) {
                $attempts = ((int) $row['attempts']) + 1;

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->outboxRepository->recordFailure($id, $attempts, null, new \DateTimeImmutable(), $leaseToken);
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

                    $this->outboxRepository->recordFailure($id, $attempts, $nextAttemptAt, null, $leaseToken);
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
