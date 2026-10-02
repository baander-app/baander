<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class OutboxRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function append(string $eventClass, string $eventName, array $payload): void
    {
        $this->connection->insert('domain_event_outbox', [
            'event_class' => $eventClass,
            'event_name' => $eventName,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'relayed_at' => null,
            'attempts' => 0,
            'next_attempt_at' => null,
            'dead_lettered_at' => null,
        ]);
    }

    /**
     * @return array<int, array{id: int, event_class: string, event_name: string, payload: string, attempts: int, lease_token: string}>
     */
    public function fetchPending(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Outbox batch size must be between 1 and 1000.');
        }
        return $this->connection->executeQuery(
            <<<'SQL'
                WITH candidates AS (
                    SELECT id FROM domain_event_outbox
                    WHERE relayed_at IS NULL
                      AND dead_lettered_at IS NULL
                      AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                      AND (lease_until IS NULL OR lease_until <= NOW())
                    ORDER BY next_attempt_at ASC NULLS FIRST, created_at ASC, id ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE domain_event_outbox AS outbox
                SET lease_token = :leaseToken, lease_until = NOW() + INTERVAL '60 seconds'
                FROM candidates
                WHERE outbox.id = candidates.id
                RETURNING outbox.id, event_class, event_name, payload, attempts, lease_token
            SQL,
            ['limit' => $limit, 'leaseToken' => bin2hex(random_bytes(32))],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    public function renewLease(int $id, string $leaseToken): bool
    {
        return $this->connection->executeStatement(
            "UPDATE domain_event_outbox SET lease_until = NOW() + INTERVAL '60 seconds'
             WHERE id = :id AND lease_token = :leaseToken AND lease_until > NOW() AND relayed_at IS NULL",
            ['id' => $id, 'leaseToken' => $leaseToken],
        ) === 1;
    }

    public function markRelayed(int $id, string $leaseToken): bool
    {
        return $this->connection->executeStatement(
            'UPDATE domain_event_outbox SET relayed_at = NOW(), lease_token = NULL, lease_until = NULL
             WHERE id = :id AND lease_token = :leaseToken AND lease_until > NOW() AND relayed_at IS NULL',
            ['id' => $id, 'leaseToken' => $leaseToken],
        ) === 1;
    }

    public function recordFailure(
        int $id,
        int $attempts,
        ?\DateTimeImmutable $nextAttemptAt,
        ?\DateTimeImmutable $deadLetteredAt,
        string $leaseToken,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE domain_event_outbox
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
