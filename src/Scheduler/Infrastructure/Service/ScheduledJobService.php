<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Service;

use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;

final class ScheduledJobService implements ScheduledJobPortInterface
{
    public function __construct(
        private readonly ScheduledJobRepositoryInterface $repository,
    ) {
    }

    public function create(
        string $name,
        string $expression,
        JobType $jobType,
        string $command,
        ?string $description = null,
        array $parameters = [],
    ): ScheduledJob {
        $job = ScheduledJob::create($name, $expression, $jobType, $command, $description, $parameters);
        $this->repository->save($job);

        return $job;
    }

    public function getById(Uuid $id): ?ScheduledJob
    {
        return $this->repository->findByUuid($id);
    }

    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    public function findByStatus(ScheduleStatus $status): array
    {
        return $this->repository->findByStatus($status);
    }

    public function save(ScheduledJob $job): void
    {
        $this->repository->save($job);
    }

    public function delete(ScheduledJob $job): void
    {
        $this->repository->delete($job);
    }
}
