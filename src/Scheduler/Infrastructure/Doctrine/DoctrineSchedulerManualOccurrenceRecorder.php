<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Durable manual requests; the occurrence ID preserves retry identity independently of the job. */
#[Exclude]
final class DoctrineSchedulerManualOccurrenceRecorder implements SchedulerManualOccurrenceRecorderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler manual recording requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    public static function fromDsn(string $databaseUrl): self
    {
        if ($databaseUrl === '' || str_contains($databaseUrl, "\0")) {
            throw new \InvalidArgumentException('Scheduler manual recording requires an explicit database URL.');
        }
        return new self(DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl)));
    }

    public function record(Uuid $jobId, Uuid $requestId): ?SchedulerOccurrence
    {
        return $this->operation(function () use ($jobId, $requestId): ?SchedulerOccurrence {
            $existing = $this->findRequest($jobId, $requestId);
            if ($existing !== null) {
                return $existing;
            }
            // Bound payload transfer before decoding, without requiring an active cron schedule.
            $row = $this->connection->fetchAssociative(<<<'SQL'
                SELECT CASE WHEN job_type IN ('messenger', 'console') THEN job_type END AS job_type,
                    CASE WHEN octet_length(command) BETWEEN 1 AND 512 THEN command END AS command,
                    CASE WHEN octet_length(parameters::text) <= 16384 THEN parameters::text END AS parameters
                FROM scheduled_jobs WHERE id = :id
                FOR UPDATE
                SQL, ['id' => $jobId->toString()]);
            // Same-job requests serialize on the job row. A retry must never rebuild a newer snapshot.
            $existing = $this->findRequest($jobId, $requestId);
            if ($existing !== null) {
                return $existing;
            }
            if ($row === false) {
                return null;
            }
            $minute = new DateTimeImmutable($this->connection->fetchOne("SELECT date_trunc('minute', clock_timestamp(), 'UTC')::text"));
            if ($row['job_type'] === null || $row['command'] === null || $row['parameters'] === null) {
                throw new \UnexpectedValueException('Scheduled manual configuration exceeds its supported bounds.');
            }
            $parameters = json_decode($row['parameters'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($parameters)) {
                throw new \UnexpectedValueException('Scheduled manual parameters must be an array or object.');
            }
            $occurrence = new SchedulerOccurrence(
                $requestId, $jobId, $minute, JobType::from($row['job_type']), $row['command'], $parameters,
                SchedulerOccurrenceOrigin::Manual,
            );
            // Global ID uniqueness and the writer's collision checks fail closed across different jobs.
            (new TransactionalSchedulerOccurrenceWriter($this->connection))->record($occurrence);
            return $occurrence;
        });
    }

    private function findRequest(Uuid $jobId, Uuid $requestId): ?SchedulerOccurrence
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT id, job_id, scheduled_for, origin,
                CASE WHEN job_type IN ('messenger', 'console') THEN job_type END AS job_type,
                CASE WHEN octet_length(command) BETWEEN 1 AND 512 THEN command END AS command,
                CASE WHEN octet_length(parameters::text) <= 16384 THEN parameters::text END AS parameters
            FROM scheduler_occurrences WHERE id = :id
            SQL, ['id' => $requestId->toString()]);
        if ($row === false) {
            return null;
        }
        if ($row['origin'] !== SchedulerOccurrenceOrigin::Manual->value || $row['job_id'] !== $jobId->toString()) {
            throw new \LogicException('Scheduler manual request identifier is already assigned to another job or origin.');
        }
        if ($row['job_type'] === null || $row['command'] === null || $row['parameters'] === null) {
            throw new \UnexpectedValueException('Persisted scheduler manual snapshot exceeds its supported bounds.');
        }
        $parameters = json_decode($row['parameters'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($parameters)) {
            throw new \UnexpectedValueException('Persisted scheduler manual parameters must be an array or object.');
        }
        return new SchedulerOccurrence(
            Uuid::fromString($row['id']), Uuid::fromString($row['job_id']), new DateTimeImmutable($row['scheduled_for']),
            JobType::from($row['job_type']), $row['command'], $parameters, SchedulerOccurrenceOrigin::Manual,
        );
    }

    /**
     * Dedicated connection: nested transactions cannot acknowledge a committed manual request.
     * Statement/lock timeouts do not bound connection setup, network I/O or commit.
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function operation(\Closure $operation): mixed
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Scheduler manual recording requires a dedicated idle autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            // Each retry lookup must observe requests committed while the job lock was awaited.
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
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
                // Preserve the original failure, including an uncertain commit.
            }
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // Failed transaction bookkeeping is not authoritative connection state.
            }
            throw $error;
        }
    }
}
