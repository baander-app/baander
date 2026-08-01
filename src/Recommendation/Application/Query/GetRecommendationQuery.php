<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

use App\Shared\Domain\Model\Uuid;

final readonly class GetRecommendationQuery
{
    public function __construct(
        private readonly Uuid $id,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
