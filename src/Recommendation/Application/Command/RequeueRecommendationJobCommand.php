<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

/**
 * Runs a failed or cancelled recommendation job again as a new job with the same mode,
 * user and metadata, linked to the original.
 */
final readonly class RequeueRecommendationJobCommand
{
    /**
     * @param string $actor the admin's user identifier, or Actor::CLI
     */
    public function __construct(
        public string $publicId,
        public string $actor,
    ) {
    }
}
