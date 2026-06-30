<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Recommendation\Application\Query\GetRecommendationsBySourceQuery;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class GetRecommendationsBySourceHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetRecommendationsBySourceQuery $query): array
    {
        return $this->recommendationRepository->findBySource(
            $query->getSourceType(),
            $query->getSourceId(),
            $query->getName(),
            $query->getLimit(),
        );
    }
}
