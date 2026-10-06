<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Shared\Application\Port\DeliveryIntentResolverInterface;
use App\Shared\Domain\Event\Outbox\OutboxRelayException;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** At-least-once handoff of committed notification intent to the durable transport. */
final readonly class RelayNotificationDeliveriesHandler
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private NotificationDeliveryRepository $repository,
        private MessageBusInterface $messageBus,
        private JsonMessageCodec $codec,
        private LoggerInterface $logger,
        private DeliveryIntentResolverInterface $intentResolver,
    ) {
    }

    public function __invoke(int $batchSize = 50): int
    {
        $relayed = 0;
        $failures = [];
        foreach ($this->repository->fetchPending($batchSize) as $row) {
            $id = (int) $row['id'];
            $leaseToken = $row['lease_token'];
            if (!$this->repository->renewLease($id, $leaseToken)) {
                continue;
            }
            try {
                $decoded = $this->codec->decode($row['payload']);
                $message = $decoded->message;
                $intent = $this->intentResolver->resolve($message);
                if ($decoded->metadata !== [] || $intent->channel !== $row['channel']
                    || $intent->notificationId !== $row['notification_id']) {
                    throw new \UnexpectedValueException('Notification delivery payload does not match its stored intent.');
                }
                $this->messageBus->dispatch($message, [new TransportNamesStamp(['async'])]);
                if (!$this->repository->markRelayed($id, $leaseToken)) {
                    throw new \RuntimeException('Notification delivery lease expired before acknowledgement.');
                }
                ++$relayed;
            } catch (\Throwable $error) {
                $attempts = (int) $row['attempts'] + 1;
                $deadLetteredAt = $attempts >= self::MAX_ATTEMPTS ? new \DateTimeImmutable() : null;
                $nextAttemptAt = $deadLetteredAt === null
                    ? (new \DateTimeImmutable())->modify(sprintf('+%d seconds', 2 * (2 ** ($attempts - 1))))
                    : null;
                $this->repository->recordFailure($id, $attempts, $nextAttemptAt, $deadLetteredAt, $leaseToken);
                $this->logger->log($deadLetteredAt === null ? 'warning' : 'error', 'Notification delivery failed for {channel}', [
                    'id' => $id, 'channel' => $row['channel'], 'attempts' => $attempts,
                    'next_attempt_at' => $nextAttemptAt?->format(\DateTimeImmutable::ATOM), 'error' => $error->getMessage(),
                ]);
                $failures[] = ['id' => $id, 'event' => $row['channel'], 'error' => $error->getMessage()];
            }
        }
        if ($failures !== []) {
            throw new OutboxRelayException(sprintf('Notification delivery failed for %d intent(s).', count($failures)), $failures);
        }

        return $relayed;
    }
}
