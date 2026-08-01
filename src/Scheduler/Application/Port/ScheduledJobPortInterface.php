<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;

interface ScheduledJobPortInterface
{
    public function create(
        string $name,
        string $expression,
        JobType $jobType,
        string $command,
        ?string $description = null,
        array $parameters = [],
    ): ScheduledJob;

    public function getById(Uuid $id): ?ScheduledJob;

    /** @return ScheduledJob[] */
    public function findAll(): array;

    /** @return ScheduledJob[] */
    public function findByStatus(ScheduleStatus $status): array;

    public function save(ScheduledJob $job): void;

    public function delete(ScheduledJob $job): void;
}
