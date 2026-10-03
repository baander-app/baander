<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrenceDispatchClaim;
use App\Scheduler\Application\Port\SchedulerOccurrenceDispatchStoreInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Durable retry reservations and transport acceptance receipts, never execution admission. */
#[Exclude]
final class DoctrineSchedulerOccurrenceDispatchStore implements SchedulerOccurrenceDispatchStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler dispatch store requires bounded positive lock/statement timeouts.');
        }
    }

    public static function fromDsn(string $databaseUrl): self
    {
        if ($databaseUrl === '' || str_contains($databaseUrl, "\0")) {
            throw new \InvalidArgumentException('Scheduler dispatch requires a nonempty database DSN.');
        }
        return new self(DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl)));
    }

    public function claimPending(int $limit = 100, int $retrySeconds = 60): array
    {
        if ($limit < 1 || $limit > 100 || $retrySeconds < 1 || $retrySeconds > 3600) {
            throw new \InvalidArgumentException('Scheduler dispatch requires limit 1..100 and retry seconds 1..3600.');
        }
        return $this->operation(function () use ($limit, $retrySeconds): array {
            $token = Uuid::v7();
            $ids = $this->connection->fetchFirstColumn(<<<'SQL'
                WITH candidates AS MATERIALIZED (
                    SELECT o.id, o.dispatch_after, o.scheduled_for
                    FROM scheduler_occurrences o
                    WHERE o.dispatched_at IS NULL
                      AND o.scheduled_for <= clock_timestamp()
                      AND o.dispatch_after <= clock_timestamp()
                      AND NOT EXISTS (SELECT 1 FROM scheduler_occurrence_executions e WHERE e.occurrence_id = o.id)
                    ORDER BY o.dispatch_after, o.scheduled_for, o.id
                    LIMIT :limit
                    FOR UPDATE OF o SKIP LOCKED
                ), reserved AS (
                    UPDATE scheduler_occurrences o
                    SET dispatch_after = clock_timestamp() + (:retry * INTERVAL '1 second'), dispatch_token = :token
                    FROM candidates c WHERE o.id = c.id
                    RETURNING o.id
                )
                SELECT r.id FROM reserved r JOIN candidates c ON c.id = r.id
                ORDER BY c.dispatch_after, c.scheduled_for, c.id
                SQL, ['limit' => $limit, 'retry' => $retrySeconds, 'token' => $token->toString()],
                ['limit' => ParameterType::INTEGER, 'retry' => ParameterType::INTEGER]);
            return array_map(static fn (mixed $id): SchedulerOccurrenceDispatchClaim => new SchedulerOccurrenceDispatchClaim(Uuid::fromString($id), $token), $ids);
        });
    }

    public function markPublished(SchedulerOccurrenceDispatchClaim $claim): bool
    {
        return $this->operation(function () use ($claim): bool {
            $parameters = ['id' => $claim->occurrenceId->toString(), 'token' => $claim->token->toString()];
            if ($this->connection->executeStatement('UPDATE scheduler_occurrences SET dispatched_at = clock_timestamp() WHERE id = :id AND dispatch_token = :token AND dispatched_at IS NULL', $parameters) === 1) {
                return true;
            }
            return $this->connection->fetchOne('SELECT 1 FROM scheduler_occurrences WHERE id = :id AND dispatch_token = :token AND dispatched_at IS NOT NULL', $parameters) !== false;
        });
    }

    /**
     * Statement/lock timeouts do not bound connection setup, network I/O or commit.
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function operation(\Closure $operation): mixed
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Scheduler occurrence store requires a dedicated idle autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            $this->connection->executeQuery("SELECT set_config('statement_timeout', :statement, true), set_config('lock_timeout', :lock, true)", ['statement' => $this->statementTimeoutMs . 'ms', 'lock' => $this->lockTimeoutMs . 'ms'])->free();
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable) {
                // Preserve the operation error if rollback is also uncertain.
            }
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // Failed begin/commit nesting is not authoritative driver state.
            }
            throw $error;
        }
    }
}
