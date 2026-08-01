<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Recommendation\Application\Query\GetTargetingRecommendationsQuery;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class GetTargetingRecommendationsHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetTargetingRecommendationsQuery $query): array
    {
        return $this->recommendationRepository->findTargeting(
            $query->getTargetType(),
            $query->getTargetId(),
            $query->getLimit(),
        );
    }
}
