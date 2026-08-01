<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Model;

use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use Cron\CronExpression;
use DateTimeImmutable;
use RuntimeException;

final class ScheduledJob
{
    private function __construct(
        private ScheduledJobState $state,
    ) {
    }

    public static function create(
        string $name,
        string $expression,
        JobType $jobType,
        string $command,
        ?string $description = null,
        array $parameters = [],
    ): self {
        $now = new DateTimeImmutable();
        $cron = new CronExpression($expression);
        $nextRun = DateTimeImmutable::createFromMutable($cron->getNextRunDate($now));

        return new self(new ScheduledJobState(
            id: new Uuid(),
            name: $name,
            expression: $expression,
            jobType: $jobType,
            command: $command,
            status: ScheduleStatus::Active,
            description: $description,
            parameters: $parameters,
            createdAt: $now,
            updatedAt: $now,
            lastRunAt: null,
            nextRunAt: $nextRun,
            lastResult: null,
            runCount: 0,
            lastFailureAt: null,
            lastError: null,
        ));
    }

    public static function reconstitute(ScheduledJobState $state): self
    {
        return new self($state);
    }

    public function getState(): ScheduledJobState
    {
        return $this->state;
    }

    // --- Identity ---

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    // --- Getters ---

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getExpression(): string
    {
        return $this->state->expression;
    }

    public function getJobType(): JobType
    {
        return $this->state->jobType;
    }

    public function getCommand(): string
    {
        return $this->state->command;
    }

    public function getStatus(): ScheduleStatus
    {
        return $this->state->status;
    }

    public function getDescription(): ?string
    {
        return $this->state->description;
    }

    public function getParameters(): array
    {
        return $this->state->parameters;
    }

    public function getLastRunAt(): ?DateTimeImmutable
    {
        return $this->state->lastRunAt;
    }

    public function getNextRunAt(): ?DateTimeImmutable
    {
        return $this->state->nextRunAt;
    }

    public function getLastResult(): ?string
    {
        return $this->state->lastResult;
    }

    public function getRunCount(): int
    {
        return $this->state->runCount;
    }

    public function getLastFailureAt(): ?DateTimeImmutable
    {
        return $this->state->lastFailureAt;
    }

    public function getLastError(): ?string
    {
        return $this->state->lastError;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    // --- Behavior ---

    public function update(
        string $name,
        string $expression,
        JobType $jobType,
        string $command,
        ?string $description,
        array $parameters,
    ): void {
        $expressionChanged = $expression !== $this->state->expression;

        $this->state->name = $name;
        $this->state->expression = $expression;
        $this->state->jobType = $jobType;
        $this->state->command = $command;
        $this->state->description = $description;
        $this->state->parameters = $parameters;
        $this->state->updatedAt = new DateTimeImmutable();

        if ($expressionChanged) {
            $this->recalculateNextRun();
        }
    }

    public function pause(): void
    {
        if ($this->state->status !== ScheduleStatus::Active) {
            throw new RuntimeException('Can only pause an active job.');
        }

        $this->state->status = ScheduleStatus::Paused;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function resume(): void
    {
        if ($this->state->status !== ScheduleStatus::Paused) {
            throw new RuntimeException('Cannot resume a job that is not paused.');
        }

        $this->state->status = ScheduleStatus::Active;
        $this->state->updatedAt = new DateTimeImmutable();
        $this->recalculateNextRun();
    }

    public function enable(): void
    {
        if ($this->state->status !== ScheduleStatus::Disabled) {
            throw new RuntimeException('Can only enable a disabled job.');
        }

        $this->state->status = ScheduleStatus::Active;
        $this->state->updatedAt = new DateTimeImmutable();
        $this->recalculateNextRun();
    }

    public function disable(): void
    {
        if ($this->state->status === ScheduleStatus::Disabled) {
            return;
        }

        $this->state->status = ScheduleStatus::Disabled;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markRunning(): void
    {
        $this->state->lastRunAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markSuccess(?string $result = null): void
    {
        $this->state->lastResult = $result;
        $this->state->runCount++;
        $this->state->lastFailureAt = null;
        $this->state->lastError = null;
        $this->state->updatedAt = new DateTimeImmutable();
        $this->recalculateNextRun();
    }

    public function markFailed(string $error): void
    {
        $this->state->lastResult = null;
        $this->state->runCount++;
        $this->state->lastFailureAt = new DateTimeImmutable();
        $this->state->lastError = $error;
        $this->state->updatedAt = new DateTimeImmutable();
        $this->recalculateNextRun();
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        if ($this->state->status !== ScheduleStatus::Active) {
            return false;
        }

        $cron = new CronExpression($this->state->expression);

        return $cron->isDue($now);
    }

    private function recalculateNextRun(): void
    {
        try {
            $cron = new CronExpression($this->state->expression);
            $this->state->nextRunAt = DateTimeImmutable::createFromMutable($cron->getNextRunDate(new DateTimeImmutable()));
        } catch (\Throwable) {
            $this->state->nextRunAt = null;
        }
    }
}
