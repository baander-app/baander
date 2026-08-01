<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Shared\Domain\Model\Uuid;

final readonly class SaveRecommendationCommand
{
    public function __construct(
        private readonly RecommendationType $sourceType,
        private readonly string $sourceId,
        private readonly RecommendationType $targetType,
        private readonly string $targetId,
        private readonly float $score,
        private readonly ?Uuid $userId = null,
        private readonly string $name = 'default',
        private readonly ?int $position = null,
    ) {
    }

    public function getSourceType(): RecommendationType
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTargetType(): RecommendationType
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
}
