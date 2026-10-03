<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Port\SchedulerOccurrenceStoreInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable intent snapshots, retained independently of deleted scheduled jobs; no execution authority. */
#[Exclude]
final class DoctrineSchedulerOccurrenceStore implements SchedulerOccurrenceStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler occurrence store requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    public function record(SchedulerOccurrence $occurrence): bool
    {
        return $this->operation(fn (): bool => (new TransactionalSchedulerOccurrenceWriter($this->connection))->record($occurrence));
    }

    public function find(Uuid $jobId, DateTimeImmutable $dueMinute): ?SchedulerOccurrence
    {
        $dueMinute = $dueMinute->setTimezone(new \DateTimeZone('UTC'));
        if ($dueMinute->format('s.u') !== '00.000000') {
            throw new \InvalidArgumentException('Scheduler occurrence lookup requires an exact UTC minute.');
        }
        return $this->operation(fn (): ?SchedulerOccurrence => $this->select($jobId, $dueMinute));
    }

    public function findById(Uuid $occurrenceId): ?SchedulerOccurrence
    {
        return $this->operation(fn (): ?SchedulerOccurrence => $this->hydrate($this->connection->fetchAssociative('SELECT id, job_id, scheduled_for, origin, job_type, command, parameters FROM scheduler_occurrences WHERE id = :id', ['id' => $occurrenceId->toString()])));
    }

    private function select(Uuid $jobId, DateTimeImmutable $scheduledFor): ?SchedulerOccurrence
    {
        $row = $this->connection->fetchAssociative("SELECT id, job_id, scheduled_for, origin, job_type, command, parameters FROM scheduler_occurrences WHERE job_id = :job AND scheduled_for = :scheduled AND origin = 'scheduled'", ['job' => $jobId->toString(), 'scheduled' => $scheduledFor->format('Y-m-d H:i:s.uP')]);
        return $this->hydrate($row);
    }

    /** @param array<string, mixed>|false $row */
    private function hydrate(array|false $row): ?SchedulerOccurrence
    {
        if ($row === false) {
            return null;
        }
        $parameters = json_decode($row['parameters'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($parameters)) {
            throw new \UnexpectedValueException('Persisted scheduler parameters must be an array or object.');
        }
        return new SchedulerOccurrence(Uuid::fromString($row['id']), Uuid::fromString($row['job_id']), new DateTimeImmutable($row['scheduled_for']), JobType::from($row['job_type']), $row['command'], $parameters, SchedulerOccurrenceOrigin::from($row['origin']));
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
