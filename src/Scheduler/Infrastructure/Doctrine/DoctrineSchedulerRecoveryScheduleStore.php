<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\Port\SchedulerRecoveryScheduleStoreInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Durable fair selection only: never materializes intents, publishes messages or admits execution. */
#[Exclude]
final class DoctrineSchedulerRecoveryScheduleStore implements SchedulerRecoveryScheduleStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler recovery selection requires bounded positive lock/statement timeouts.');
        }
    }

    public static function fromDsn(string $databaseUrl): self
    {
        if ($databaseUrl === '' || str_contains($databaseUrl, "\0")) {
            throw new \InvalidArgumentException('Scheduler recovery selection requires a nonempty database DSN.');
        }
        return new self(DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl)));
    }

    public function claimPendingJobs(int $limit = 10, int $retrySeconds = 60): array
    {
        if ($limit < 1 || $limit > 100 || $retrySeconds < 1 || $retrySeconds > 3600) {
            throw new \InvalidArgumentException('Scheduler recovery selection requires limit 1..100 and retry seconds 1..3600.');
        }
        return $this->operation(function () use ($limit, $retrySeconds): array {
            $ids = $this->connection->fetchFirstColumn(<<<'SQL'
                WITH candidates AS MATERIALIZED (
                    SELECT j.id, j.recovery_after
                    FROM scheduled_jobs j
                    WHERE j.status = 'active' AND j.recovery_after <= clock_timestamp()
                      AND (j.evaluated_through IS NULL OR j.evaluated_through < date_trunc('minute', clock_timestamp(), 'UTC'))
                    ORDER BY j.recovery_after, j.id
                    LIMIT :limit FOR UPDATE OF j SKIP LOCKED
                ), deferred AS (
                    UPDATE scheduled_jobs j
                    SET recovery_after = clock_timestamp() + (:retry * INTERVAL '1 second')
                    FROM candidates c WHERE j.id = c.id RETURNING j.id
                )
                SELECT d.id FROM deferred d JOIN candidates c ON c.id = d.id
                ORDER BY c.recovery_after, c.id
                SQL, ['limit' => $limit, 'retry' => $retrySeconds],
                ['limit' => ParameterType::INTEGER, 'retry' => ParameterType::INTEGER]);
            return array_map(static fn (mixed $id): Uuid => Uuid::fromString($id), $ids);
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
            throw new \LogicException('Scheduler recovery selection requires a dedicated idle autocommit connection.');
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
