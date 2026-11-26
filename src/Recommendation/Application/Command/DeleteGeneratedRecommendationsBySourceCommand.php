<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Shared\Domain\Model\Uuid;

/** Clears a source's generator rows before a generation run for the user writes them anew. */
final readonly class DeleteGeneratedRecommendationsBySourceCommand
{
    /** @param Uuid|null $userId the user the run generates for; null for a run for no user */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public ?Uuid $userId,
    ) {
    }
}
