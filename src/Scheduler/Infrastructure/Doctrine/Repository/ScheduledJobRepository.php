<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine\Repository;

use App\Scheduler\Domain\Exception\ScheduledJobConflict;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Model\ScheduledJobState;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Authoritative DBAL snapshots and atomic UUID revision comparisons; no ORM identity-map reads or flushes.
 * Uses the caller's connection/transaction. Returned revisions are provisional inside an outer transaction:
 * discard all involved domain snapshots after rollback or uncertain commit, then reload before further writes.
 */
final class ScheduledJobRepository implements ScheduledJobRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function save(ScheduledJob $job): void
    {
        $state = $job->getState();
        $revision = Uuid::v7();
        $parameters = [
            'id' => $state->id->toString(), 'revision' => $revision->toString(),
            'name' => $state->name, 'expression' => $state->expression,
            'job_type' => $state->jobType->value, 'command' => $state->command,
            'status' => $state->status->value, 'description' => $state->description,
            'parameters' => json_encode($state->parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'created_at' => $state->createdAt->format('Y-m-d H:i:s.uP'),
            'updated_at' => $state->updatedAt->format('Y-m-d H:i:s.uP'),
            'last_run_at' => $state->lastRunAt?->format('Y-m-d H:i:s.uP'),
            'next_run_at' => $state->nextRunAt?->format('Y-m-d H:i:s.uP'),
            'last_result' => $state->lastResult, 'run_count' => $state->runCount,
            'last_failure_at' => $state->lastFailureAt?->format('Y-m-d H:i:s.uP'),
            'last_error' => $state->lastError,
        ];
        if ($state->revision === null) {
            $sql = <<<'SQL'
                INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, description,
                    parameters, created_at, updated_at, last_run_at, next_run_at, last_result, run_count, last_failure_at, last_error)
                VALUES (:id, :revision, :name, :expression, :job_type, :command, :status, :description,
                    CAST(:parameters AS JSON), :created_at, :updated_at, :last_run_at, :next_run_at, :last_result, :run_count, :last_failure_at, :last_error)
                ON CONFLICT (id) DO NOTHING RETURNING revision
                SQL;
        } else {
            $parameters['expected_revision'] = $state->revision->toString();
            $sql = <<<'SQL'
                UPDATE scheduled_jobs SET revision = :revision, name = :name, expression = :expression, job_type = :job_type,
                    command = :command, status = :status, description = :description, parameters = CAST(:parameters AS JSON),
                    created_at = :created_at, updated_at = :updated_at, last_run_at = :last_run_at, next_run_at = :next_run_at,
                    last_result = :last_result, run_count = :run_count, last_failure_at = :last_failure_at, last_error = :last_error
                WHERE id = :id AND revision = :expected_revision RETURNING revision
                SQL;
        }
        $savedRevision = $this->entityManager->getConnection()->fetchOne($sql, $parameters, ['run_count' => ParameterType::INTEGER]);
        if ($savedRevision === false) {
            throw new ScheduledJobConflict();
        }
        $state->revision = Uuid::fromString($savedRevision);
    }

    public function findByUuid(Uuid $uuid): ?ScheduledJob
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM scheduled_jobs WHERE id = :id', ['id' => $uuid->toString()]);
        return $row === false ? null : $this->toDomain($row);
    }

    /** @return list<ScheduledJob> */
    public function findAll(): array
    {
        return array_map($this->toDomain(...), $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM scheduled_jobs ORDER BY created_at DESC, id'));
    }

    /** @return list<ScheduledJob> */
    public function findByStatus(ScheduleStatus $status): array
    {
        return array_map($this->toDomain(...), $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM scheduled_jobs WHERE status = :status ORDER BY created_at DESC, id', ['status' => $status->value]));
    }

    public function delete(ScheduledJob $job): void
    {
        $state = $job->getState();
        if ($state->revision === null || $this->entityManager->getConnection()->fetchOne('DELETE FROM scheduled_jobs WHERE id = :id AND revision = :revision RETURNING id', ['id' => $state->id->toString(), 'revision' => $state->revision->toString()]) === false) {
            throw new ScheduledJobConflict();
        }
        // Keep the consumed revision: a deleted persisted snapshot must never become insertable again.
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row): ScheduledJob
    {
        $parameters = json_decode($row['parameters'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($parameters)) {
            throw new \UnexpectedValueException('Scheduled job parameters must hydrate as an array.');
        }
        return ScheduledJob::reconstitute(new ScheduledJobState(
            id: Uuid::fromString($row['id']), name: $row['name'], expression: $row['expression'],
            jobType: JobType::from($row['job_type']), command: $row['command'], status: ScheduleStatus::from($row['status']),
            description: $row['description'], parameters: $parameters,
            createdAt: new \DateTimeImmutable($row['created_at']), updatedAt: new \DateTimeImmutable($row['updated_at']),
            lastRunAt: $row['last_run_at'] === null ? null : new \DateTimeImmutable($row['last_run_at']),
            nextRunAt: $row['next_run_at'] === null ? null : new \DateTimeImmutable($row['next_run_at']),
            lastResult: $row['last_result'], runCount: (int) $row['run_count'],
            lastFailureAt: $row['last_failure_at'] === null ? null : new \DateTimeImmutable($row['last_failure_at']),
            lastError: $row['last_error'], revision: Uuid::fromString($row['revision']),
        ));
    }
}
