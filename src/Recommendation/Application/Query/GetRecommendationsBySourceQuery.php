<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

final readonly class GetRecommendationsBySourceQuery
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $sourceId,
        private readonly string $name = 'default',
        private readonly int $limit = 100,
    ) {
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
