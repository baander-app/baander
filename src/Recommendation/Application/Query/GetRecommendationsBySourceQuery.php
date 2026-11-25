<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

use App\Shared\Domain\Model\Uuid;

/** Rows from a source: the viewer's and the global ones, or every user's for an administrator. */
final readonly class GetRecommendationsBySourceQuery
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $sourceId,
        public Uuid $viewerId,
        public bool $administrator,
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
