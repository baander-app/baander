<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\SchedulerOccurrenceMaterializerInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Per-job recovery primitive, deliberately not wired into a poller or dispatch loop. */
#[Exclude]
final class DoctrineSchedulerOccurrenceMaterializer implements SchedulerOccurrenceMaterializerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler recovery requires bounded positive lock/statement timeouts.');
        }
    }

    public function materialize(Uuid $jobId, int $minuteLimit = 60): int
    {
        if ($minuteLimit < 1 || $minuteLimit > 1000) {
            throw new \InvalidArgumentException('Scheduler recovery requires a minute limit of 1..1000.');
        }
        return $this->operation(function () use ($jobId, $minuteLimit): int {
            // Bound payload transfer before decoding. The schedule API currently allows larger values.
            $row = $this->connection->fetchAssociative(<<<'SQL'
                SELECT evaluated_through,
                    CASE WHEN octet_length(expression) BETWEEN 1 AND 1024 THEN expression END AS expression,
                    CASE WHEN job_type IN ('messenger', 'console') THEN job_type END AS job_type,
                    CASE WHEN octet_length(command) BETWEEN 1 AND 512 THEN command END AS command,
                    CASE WHEN octet_length(parameters::text) <= 16384 THEN parameters::text END AS parameters
                FROM scheduled_jobs WHERE id = :id AND status = 'active'
                FOR UPDATE SKIP LOCKED
                SQL, ['id' => $jobId->toString()]);
            if ($row === false) {
                return 0;
            }
            // Capture the cutoff after acquiring the row lock, with no process/session timezone dependency.
            $cutoff = new DateTimeImmutable($this->connection->fetchOne("SELECT date_trunc('minute', clock_timestamp(), 'UTC')::text"));
            $cutoff = $cutoff->setTimezone(new DateTimeZone('UTC'));
            if ($row['expression'] === null || $row['job_type'] === null || $row['command'] === null || $row['parameters'] === null) {
                throw new \UnexpectedValueException('Scheduled recovery configuration exceeds its supported bounds.');
            }
            $cron = new CronExpression($row['expression']);
            $parameters = json_decode($row['parameters'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($parameters)) {
                throw new \UnexpectedValueException('Scheduled recovery parameters must be an array or object.');
            }
            // Validate before initialization or sparse scans; the DTO enforces the eight-array nesting bound.
            $snapshot = new SchedulerOccurrence(Uuid::v7(), $jobId, $cutoff, JobType::from($row['job_type']), $row['command'], $parameters);
            if ($row['evaluated_through'] === null) {
                $this->advance($jobId, $cutoff);
                return 0;
            }
            $cursor = (new DateTimeImmutable($row['evaluated_through']))->setTimezone(new DateTimeZone('UTC'));
            if ($cursor >= $cutoff) {
                // Backward clock movement never rewinds evaluated history.
                return 0;
            }
            $writer = new TransactionalSchedulerOccurrenceWriter($this->connection);
            $inserted = 0;
            for ($scanned = 0; $scanned < $minuteLimit && $cursor < $cutoff; ++$scanned) {
                $cursor = $cursor->modify('+1 minute');
                if ($cron->isDue($cursor, 'UTC')) {
                    $inserted += (int) $writer->record(new SchedulerOccurrence(
                        Uuid::v7(), $jobId, $cursor, $snapshot->jobType, $snapshot->command, $snapshot->parameters,
                    ));
                }
            }
            $this->advance($jobId, $cursor);
            return $inserted;
        });
    }

    private function advance(Uuid $jobId, DateTimeImmutable $minute): void
    {
        $this->connection->executeStatement('UPDATE scheduled_jobs SET evaluated_through = :minute WHERE id = :id', ['minute' => $minute->format('Y-m-d H:i:s.uP'), 'id' => $jobId->toString()]);
    }

    /**
     * Dedicated connection: nested transactions cannot acknowledge a committed recovery window.
     * Statement/lock timeouts do not bound PHP cron evaluation, connection setup, network I/O or commit.
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function operation(\Closure $operation): mixed
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Scheduler recovery requires a dedicated idle autocommit connection.');
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
