<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

use App\Shared\Domain\Model\Uuid;

final readonly class GetRecommendationsForUserQuery
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly int $limit = 50,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
