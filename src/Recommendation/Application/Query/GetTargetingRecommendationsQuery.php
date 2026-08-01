<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

final readonly class GetTargetingRecommendationsQuery
{
    public function __construct(
        private readonly string $targetType,
        private readonly string $targetId,
        private readonly int $limit = 100,
    ) {
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
