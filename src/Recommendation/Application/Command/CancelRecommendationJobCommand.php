<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

/**
 * Cancels a pending or running recommendation job. The run stops at its next check:
 * a pool worker every 50 songs, a run in another process between strategies.
 */
final readonly class CancelRecommendationJobCommand
{
    public function __construct(
        public string $publicId,
    ) {
    }
}
