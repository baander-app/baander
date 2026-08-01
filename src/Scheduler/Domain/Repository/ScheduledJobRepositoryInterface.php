<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Repository;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;

interface ScheduledJobRepositoryInterface
{
    public function save(ScheduledJob $job): void;

    public function findByUuid(Uuid $uuid): ?ScheduledJob;

    /** @return ScheduledJob[] */
    public function findAll(): array;

    /** @return ScheduledJob[] */
    public function findByStatus(ScheduleStatus $status): array;

    public function delete(ScheduledJob $job): void;
}
