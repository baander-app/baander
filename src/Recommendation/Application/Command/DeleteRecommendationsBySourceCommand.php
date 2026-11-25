<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class DeleteRecommendationsBySourceCommand
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $sourceId,
        public ?Uuid $actorId = null,
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
}
