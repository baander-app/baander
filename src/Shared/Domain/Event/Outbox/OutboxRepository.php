<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use Doctrine\DBAL\Connection;

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
     * @return array<int, array{id: int, event_class: string, event_name: string, payload: string, attempts: int}>
     */
    public function fetchPending(int $limit = 50): array
    {
        return $this->connection->executeQuery(
            <<<'SQL'
                SELECT id, event_class, event_name, payload, attempts
                FROM domain_event_outbox
                WHERE relayed_at IS NULL
                  AND dead_lettered_at IS NULL
                  AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                ORDER BY next_attempt_at ASC NULLS FIRST, created_at ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            SQL,
            ['limit' => $limit],
            ['limit' => \PDO::PARAM_INT],
        )->fetchAllAssociative();
    }

    public function markRelayed(int $id): void
    {
        $this->connection->update('domain_event_outbox', [
            'relayed_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ], ['id' => $id]);
    }

    public function recordFailure(
        int $id,
        int $attempts,
        ?\DateTimeImmutable $nextAttemptAt,
        ?\DateTimeImmutable $deadLetteredAt,
    ): void {
        $data = [
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt?->format(\DateTimeImmutable::ATOM),
            'dead_lettered_at' => $deadLetteredAt?->format(\DateTimeImmutable::ATOM),
        ];

        $this->connection->update('domain_event_outbox', $data, ['id' => $id]);
    }
}
