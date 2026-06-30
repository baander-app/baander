<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Recommendation\Domain\Model\Recommendation;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class RecommendationResultStamp implements StampInterface
{
    public function __construct(
        private Recommendation $recommendation,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof Recommendation ? new self($result) : null;
    }

    public function getRecommendation(): Recommendation
    {
        return $this->recommendation;
    }
}
