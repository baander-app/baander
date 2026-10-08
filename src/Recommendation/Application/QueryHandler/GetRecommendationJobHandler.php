<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Recommendation\Application\Query\GetRecommendationJobQuery;
use App\Recommendation\Application\Service\RecommendationJobFinder;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetRecommendationJobHandler
{
    public function __construct(
        private RecommendationJobFinder $finder,
    ) {
    }

    /** @throws NotFoundException */
    public function __invoke(GetRecommendationJobQuery $query): RecommendationJob
    {
        return $this->finder->byPublicId($query->publicId);
    }
}
