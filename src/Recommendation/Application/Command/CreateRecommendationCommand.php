<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * A member stores a recommendation of their own. The types are among
 * RecommendationLimits::ENTITY_TYPES; the source and target IDs are internal.
 */
final readonly class CreateRecommendationCommand
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $targetType,
        public string $targetId,
        public float $score,
        public Uuid $userId,
        public string $name = 'default',
        public ?int $position = null,
    ) {
    }
}
