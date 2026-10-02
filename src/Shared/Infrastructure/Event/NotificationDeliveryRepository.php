<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class NotificationDeliveryRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(string $channel, string $notificationPublicId, string $payload): void
    {
        $this->connection->executeStatement(
            'INSERT INTO domain_event_outbox_delivery (channel, notification_id, payload)
             VALUES (:channel, :notificationId, :payload)
             ON CONFLICT (channel, notification_id) DO NOTHING',
            ['channel' => $channel, 'notificationId' => $notificationPublicId, 'payload' => $payload],
        );
    }

    /** @return list<array{id: int, channel: string, notification_id: string, payload: string, attempts: int, lease_token: string}> */
    public function fetchPending(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Notification delivery batch size must be between 1 and 1000.');
        }

        return $this->connection->executeQuery(
            <<<'SQL'
                WITH candidates AS (
                    SELECT id FROM domain_event_outbox_delivery
                    WHERE relayed_at IS NULL
                      AND dead_lettered_at IS NULL
                      AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                      AND (lease_until IS NULL OR lease_until <= NOW())
                    ORDER BY next_attempt_at ASC NULLS FIRST, created_at ASC, id ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE domain_event_outbox_delivery AS delivery
                SET lease_token = :leaseToken, lease_until = NOW() + INTERVAL '60 seconds'
                FROM candidates
                WHERE delivery.id = candidates.id
                RETURNING delivery.id, channel, notification_id, payload, attempts, lease_token
            SQL,
            ['limit' => $limit, 'leaseToken' => bin2hex(random_bytes(32))],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    public function renewLease(int $id, string $leaseToken): bool
    {
        return $this->connection->executeStatement(
            "UPDATE domain_event_outbox_delivery SET lease_until = NOW() + INTERVAL '60 seconds'
             WHERE id = :id AND lease_token = :leaseToken AND lease_until > NOW() AND relayed_at IS NULL",
            ['id' => $id, 'leaseToken' => $leaseToken],
        ) === 1;
    }

    public function markRelayed(int $id, string $leaseToken): bool
    {
        return $this->connection->executeStatement(
            'UPDATE domain_event_outbox_delivery SET relayed_at = NOW(), lease_token = NULL, lease_until = NULL
             WHERE id = :id AND lease_token = :leaseToken AND lease_until > NOW() AND relayed_at IS NULL',
            ['id' => $id, 'leaseToken' => $leaseToken],
        ) === 1;
    }

    public function recordFailure(int $id, int $attempts, ?\DateTimeImmutable $nextAttemptAt, ?\DateTimeImmutable $deadLetteredAt, string $leaseToken): bool
    {
        return $this->connection->executeStatement(
            'UPDATE domain_event_outbox_delivery
             SET attempts = :attempts, next_attempt_at = :nextAttemptAt, dead_lettered_at = :deadLetteredAt,
                 lease_token = NULL, lease_until = NULL
             WHERE id = :id AND lease_token = :leaseToken AND lease_until > NOW() AND relayed_at IS NULL',
            [
                'id' => $id, 'leaseToken' => $leaseToken, 'attempts' => $attempts,
                'nextAttemptAt' => $nextAttemptAt?->format(\DateTimeImmutable::ATOM),
                'deadLetteredAt' => $deadLetteredAt?->format(\DateTimeImmutable::ATOM),
            ],
        ) === 1;
    }
}
