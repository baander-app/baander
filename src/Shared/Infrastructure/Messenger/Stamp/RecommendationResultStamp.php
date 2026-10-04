<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Recommendation\Domain\Model\Recommendation;

final readonly class RecommendationResultStamp implements ResultStampInterface
{
    public function __construct(
        private Recommendation $recommendation,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof Recommendation ? new self($result) : null;
    }

    public function getRecommendation(): Recommendation
    {
        return $this->recommendation;
    }
}
