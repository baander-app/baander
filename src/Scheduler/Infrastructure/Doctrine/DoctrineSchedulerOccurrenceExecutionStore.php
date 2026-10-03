<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\SchedulerOccurrenceExecutionStoreInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Permanent one-shot admission, on a dedicated idle autocommit connection; no automatic reclaim. */
#[Exclude]
final class DoctrineSchedulerOccurrenceExecutionStore implements SchedulerOccurrenceExecutionStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler execution store requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    public static function fromDsn(string $databaseUrl): self
    {
        if ($databaseUrl === '' || str_contains($databaseUrl, "\0")) {
            throw new \InvalidArgumentException('Scheduler execution store requires an explicit database URL.');
        }
        return new self(DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl)));
    }

    public function begin(Uuid $occurrenceId, Uuid $attemptId): ?SchedulerOccurrence
    {
        return $this->operation(function () use ($occurrenceId, $attemptId): ?SchedulerOccurrence {
            $parameters = ['occurrence' => $occurrenceId->toString(), 'attempt' => $attemptId->toString()];
            $inserted = $this->connection->executeStatement(<<<'SQL'
                INSERT INTO scheduler_occurrence_executions (occurrence_id, attempt_id)
                SELECT id, CAST(:attempt AS UUID) FROM scheduler_occurrences
                WHERE id = :occurrence AND scheduled_for <= clock_timestamp()
                ON CONFLICT DO NOTHING
                SQL, $parameters);
            if ($inserted !== 1) {
                return null;
            }
            $row = $this->connection->fetchAssociative(<<<'SQL'
                SELECT occurrence.id, occurrence.job_id, occurrence.scheduled_for, occurrence.job_type, occurrence.command, occurrence.parameters
                FROM scheduler_occurrences occurrence
                JOIN scheduler_occurrence_executions execution ON execution.occurrence_id = occurrence.id
                WHERE execution.occurrence_id = :occurrence AND execution.attempt_id = :attempt
                SQL, $parameters);
            if ($row === false) {
                throw new \UnexpectedValueException('New execution admission has no authoritative occurrence snapshot.');
            }
            $snapshot = json_decode($row['parameters'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new \UnexpectedValueException('Persisted scheduler parameters must be an array or object.');
            }
            return new SchedulerOccurrence(Uuid::fromString($row['id']), Uuid::fromString($row['job_id']), new DateTimeImmutable($row['scheduled_for']), JobType::from($row['job_type']), $row['command'], $snapshot);
        });
    }

    /** A returned adapter is not proof of successful work or stopped descendants. */
    public function markReturned(Uuid $occurrenceId, Uuid $attemptId): bool
    {
        return $this->operation(function () use ($occurrenceId, $attemptId): bool {
            $parameters = ['occurrence' => $occurrenceId->toString(), 'attempt' => $attemptId->toString()];
            $updated = $this->connection->executeStatement(<<<'SQL'
                UPDATE scheduler_occurrence_executions SET returned_at = clock_timestamp()
                WHERE occurrence_id = :occurrence AND attempt_id = :attempt AND returned_at IS NULL
                SQL, $parameters);
            return $updated === 1 || $this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM scheduler_occurrence_executions
                WHERE occurrence_id = :occurrence AND attempt_id = :attempt AND returned_at IS NOT NULL
                SQL, $parameters) !== false;
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
            throw new \LogicException('Scheduler execution store requires a dedicated idle autocommit connection.');
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
