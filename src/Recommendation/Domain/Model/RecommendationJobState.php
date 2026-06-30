<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Model;

use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class RecommendationJobState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly bool $isFull,
        public readonly ?Uuid $userId,
        public RecommendationJobStatus $status,
        public int $totalSongs,
        public int $completedSongs,
        public string $currentStrategy,
        /** @var array<string, int> Counts per strategy */
        public array $strategyCounts,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $failReason = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        /** @var array<string, mixed> Arbitrary metadata for debugging/requeuing */
        public array $metadata = [],
        /** @var Uuid|null ID of the original job if this is a requeue */
        public ?Uuid $originalJobId = null,
    ) {
    }
}
