<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

use App\Shared\Domain\Model\Uuid;

/** Rows to a target: the viewer's and the global ones, or every user's for an administrator. */
final readonly class GetTargetingRecommendationsQuery
{
    public function __construct(
        private readonly string $targetType,
        private readonly string $targetId,
        public Uuid $viewerId,
        public bool $administrator,
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
