<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

final readonly class GetRecommendationJobQuery
{
    public function __construct(
        public string $publicId,
    ) {
    }
}
