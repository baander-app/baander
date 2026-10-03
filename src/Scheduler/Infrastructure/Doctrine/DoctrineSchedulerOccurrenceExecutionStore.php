<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Exception\ScheduledOccurrenceJobBusy;
use App\Scheduler\Application\Port\SchedulerOccurrenceExecutionStoreInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Permanent occurrence admission with durable per-job wrapper serialization; no automatic reclaim. */
#[Exclude]
final class DoctrineSchedulerOccurrenceExecutionStore implements SchedulerOccurrenceExecutionStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?DeploymentLease $authority = null,
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
        $namespace = getenv('BAANDER_WORKER_NAMESPACE');
        $bootId = getenv('BAANDER_WORKER_BOOT_ID');
        $epoch = getenv('BAANDER_WORKER_LEASE_EPOCH');
        $authority = null;
        if ($namespace !== false && $bootId !== false && $epoch !== false) {
            $epochValue = filter_var($epoch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            try {
                if (preg_match('/\A[1-9][0-9]*\z/D', $epoch) !== 1 || $epochValue === false) {
                    throw new \InvalidArgumentException();
                }
                $authority = new DeploymentLease($namespace, $bootId, $epochValue);
            } catch (\InvalidArgumentException) {
                throw new \RuntimeException('Scheduler execution authority context is invalid.');
            }
        }
        return new self(DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl)), $authority);
    }

    public function begin(Uuid $occurrenceId, Uuid $attemptId): ?SchedulerOccurrence
    {
        $authority = $this->authority;
        if ($authority === null) {
            throw new \RuntimeException('Scheduler execution admission requires active deployment authority.');
        }
        return $this->operation(function () use ($occurrenceId, $attemptId, $authority): ?SchedulerOccurrence {
            $this->requireActiveAuthority($authority);
            $parameters = ['occurrence' => $occurrenceId->toString(), 'attempt' => $attemptId->toString()];
            $inserted = $this->connection->executeStatement(<<<'SQL'
                INSERT INTO scheduler_occurrence_executions (occurrence_id, job_id, attempt_id, deployment_namespace, deployment_boot_id, deployment_epoch)
                SELECT id, job_id, CAST(:attempt AS UUID), :namespace, :boot, :epoch FROM scheduler_occurrences
                WHERE id = :occurrence AND scheduled_for <= clock_timestamp()
                ON CONFLICT DO NOTHING
                SQL, $parameters + ['namespace' => $authority->namespace, 'boot' => $authority->bootId, 'epoch' => $authority->epoch]);
            if ($inserted !== 1) {
                // A conflicting insert may have committed after the INSERT snapshot.
                // Read again before classifying: every consumed occurrence/attempt is a no-op.
                $duplicate = $this->connection->fetchOne(<<<'SQL'
                    SELECT 1 FROM scheduler_occurrence_executions
                    WHERE occurrence_id = :occurrence OR attempt_id = :attempt
                    SQL, $parameters) !== false;
                if (!$duplicate) {
                    $job = $this->connection->fetchOne(<<<'SQL'
                        SELECT job_id FROM scheduler_occurrences
                        WHERE id = :occurrence AND scheduled_for <= clock_timestamp()
                        SQL, ['occurrence' => $occurrenceId->toString()]);
                    if ($job !== false) {
                        // The blocker may already have returned. Retry conservatively;
                        // acknowledging here would discard an unconsumed due occurrence.
                        throw new ScheduledOccurrenceJobBusy(Uuid::fromString($job));
                    }
                }
                return null;
            }
            $row = $this->connection->fetchAssociative(<<<'SQL'
                SELECT occurrence.id, occurrence.job_id, occurrence.scheduled_for, occurrence.origin, occurrence.job_type, occurrence.command, occurrence.parameters
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
            $occurrence = new SchedulerOccurrence(Uuid::fromString($row['id']), Uuid::fromString($row['job_id']), new DateTimeImmutable($row['scheduled_for']), JobType::from($row['job_type']), $row['command'], $snapshot, SchedulerOccurrenceOrigin::from($row['origin']));
            $this->requireActiveAuthority($authority);
            return $occurrence;
        });
    }

    /** A returned adapter is not proof of successful work or stopped descendants. */
    public function markReturned(Uuid $occurrenceId, Uuid $attemptId): bool
    {
        $authority = $this->authority;
        if ($authority === null) {
            return false;
        }
        return $this->operation(function () use ($occurrenceId, $attemptId, $authority): bool {
            $parameters = ['occurrence' => $occurrenceId->toString(), 'attempt' => $attemptId->toString(), 'namespace' => $authority->namespace, 'boot' => $authority->bootId, 'epoch' => $authority->epoch];
            $updated = $this->connection->executeStatement(<<<'SQL'
                UPDATE scheduler_occurrence_executions SET returned_at = clock_timestamp()
                WHERE occurrence_id = :occurrence AND attempt_id = :attempt
                    AND deployment_namespace = :namespace AND deployment_boot_id = :boot AND deployment_epoch = :epoch
                    AND returned_at IS NULL
                SQL, $parameters);
            return $updated === 1 || $this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM scheduler_occurrence_executions
                WHERE occurrence_id = :occurrence AND attempt_id = :attempt
                    AND deployment_namespace = :namespace AND deployment_boot_id = :boot AND deployment_epoch = :epoch
                    AND returned_at IS NOT NULL
                SQL, $parameters) !== false;
        });
    }

    /** The lock serializes admission with lease replacement; it cannot fence effects after commit. */
    private function requireActiveAuthority(DeploymentLease $authority): void
    {
        $locked = $this->connection->fetchOne('SELECT 1 FROM worker_deployment_leases WHERE namespace = :namespace FOR UPDATE', ['namespace' => $authority->namespace]);
        // Evaluate database time after lock acquisition, not in a pre-wait predicate.
        $active = $locked !== false && $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM worker_deployment_leases
            WHERE namespace = :namespace AND owner_boot_id = :boot AND epoch = :epoch
                AND state = 'active' AND expires_at > clock_timestamp()
            SQL, ['namespace' => $authority->namespace, 'boot' => $authority->bootId, 'epoch' => $authority->epoch]) !== false;
        if (!$active) {
            throw new \RuntimeException('Scheduler execution admission requires active deployment authority.');
        }
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
