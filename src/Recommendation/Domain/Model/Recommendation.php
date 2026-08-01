<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Model;

use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class Recommendation
{
    private function __construct(
        private readonly Uuid $id,
        private readonly string $name,
        private readonly RecommendationType $sourceType,
        private readonly string $sourceId,
        private readonly RecommendationType $targetType,
        private readonly string $targetId,
        private float $score,
        private ?int $position,
        private ?Uuid $userId,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId,
        float $score,
        ?Uuid $userId = null,
        string $name = 'default',
        ?int $position = null,
    ): self {
        return new self(
            new Uuid(),
            $name,
            RecommendationType::fromString($sourceType),
            $sourceId,
            RecommendationType::fromString($targetType),
            $targetId,
            $score,
            $position,
            $userId,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }

    public static function reconstitute(
        Uuid $id,
        string $name,
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId,
        float $score,
        ?int $position,
        ?Uuid $userId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $name,
            RecommendationType::fromString($sourceType),
            $sourceId,
            RecommendationType::fromString($targetType),
            $targetId,
            $score,
            $position,
            $userId,
            $createdAt,
            $updatedAt,
        );
    }

    public function updateScore(float $score): void
    {
        $this->score = $score;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
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

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
