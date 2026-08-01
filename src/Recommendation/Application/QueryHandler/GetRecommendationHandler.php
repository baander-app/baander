<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Recommendation\Application\Query\GetRecommendationQuery;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class GetRecommendationHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetRecommendationQuery $query): ?Recommendation
    {
        return $this->recommendationRepository->findByUuid($query->getId());
    }
}
