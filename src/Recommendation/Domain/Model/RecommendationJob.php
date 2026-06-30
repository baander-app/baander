<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Model;

use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class RecommendationJob
{
    private function __construct(
        private RecommendationJobState $state,
    ) {
    }

    public static function create(
        bool $isFull,
        ?Uuid $userId = null,
        array $metadata = [],
        ?Uuid $originalJobId = null,
    ): self {
        return new self(new RecommendationJobState(
            id: new Uuid(),
            publicId: new PublicId(),
            isFull: $isFull,
            userId: $userId,
            status: RecommendationJobStatus::Pending,
            totalSongs: 0,
            completedSongs: 0,
            currentStrategy: '',
            strategyCounts: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            metadata: $metadata,
            originalJobId: $originalJobId,
        ));
    }

    public static function reconstitute(RecommendationJobState $state): self
    {
        return new self($state);
    }

    public function markInProgress(int $totalSongs): void
    {
        if ($this->state->status !== RecommendationJobStatus::Pending) {
            throw new RuntimeException(sprintf(
                'Cannot mark job as in_progress from status "%s".',
                $this->state->status->value,
            ));
        }

        $this->state->status = RecommendationJobStatus::InProgress;
        $this->state->totalSongs = $totalSongs;
        $this->state->startedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateProgress(string $strategy, int $completedCount, array $strategyCounts): void
    {
        if ($this->state->status !== RecommendationJobStatus::InProgress) {
            return;
        }

        $this->state->currentStrategy = $strategy;
        $this->state->completedSongs = $completedCount;
        $this->state->strategyCounts = $strategyCounts;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markCompleted(array $finalCounts): void
    {
        if ($this->state->status !== RecommendationJobStatus::InProgress) {
            throw new RuntimeException(sprintf(
                'Cannot mark job as completed from status "%s".',
                $this->state->status->value,
            ));
        }

        $this->state->status = RecommendationJobStatus::Completed;
        $this->state->strategyCounts = $finalCounts;
        $this->state->completedSongs = $this->state->totalSongs;
        $this->state->completedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        if ($this->state->status === RecommendationJobStatus::Completed
            || $this->state->status === RecommendationJobStatus::Cancelled
            || $this->state->status === RecommendationJobStatus::Failed
        ) {
            return;
        }

        $this->state->failReason = $reason;
        $this->state->status = RecommendationJobStatus::Failed;
        $this->state->completedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        if ($this->state->status === RecommendationJobStatus::Completed
            || $this->state->status === RecommendationJobStatus::Failed
        ) {
            return;
        }

        $this->state->status = RecommendationJobStatus::Cancelled;
        $this->state->completedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getState(): RecommendationJobState
    {
        return $this->state;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function isFull(): bool
    {
        return $this->state->isFull;
    }

    public function getUserId(): ?Uuid
    {
        return $this->state->userId;
    }

    public function getStatus(): RecommendationJobStatus
    {
        return $this->state->status;
    }

    public function getTotalSongs(): int
    {
        return $this->state->totalSongs;
    }

    public function getCompletedSongs(): int
    {
        return $this->state->completedSongs;
    }

    public function getCurrentStrategy(): string
    {
        return $this->state->currentStrategy;
    }

    /** @return array<string, int> */
    public function getStrategyCounts(): array
    {
        return $this->state->strategyCounts;
    }

    public function getFailReason(): ?string
    {
        return $this->state->failReason;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->state->startedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->state->completedAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->state->metadata;
    }

    public function getOriginalJobId(): ?Uuid
    {
        return $this->state->originalJobId;
    }

    public function withMetadata(array $metadata): self
    {
        $this->state->metadata = [...$this->state->metadata, ...$metadata];
        $this->state->updatedAt = new DateTimeImmutable();
        return $this;
    }
}
