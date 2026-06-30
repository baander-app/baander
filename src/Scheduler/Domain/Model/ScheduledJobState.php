<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Model;

use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use RuntimeException;

final class ScheduledJobState
{
    public function __construct(
        public readonly Uuid $id,
        public string $name,
        public string $expression,
        public JobType $jobType,
        public string $command,
        public ScheduleStatus $status,
        public ?string $description,
        /** @var array<string, mixed> */
        public array $parameters,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $lastRunAt = null,
        public ?DateTimeImmutable $nextRunAt = null,
        public ?string $lastResult = null,
        public int $runCount = 0,
        public ?DateTimeImmutable $lastFailureAt = null,
        public ?string $lastError = null,
    ) {
    }
}
