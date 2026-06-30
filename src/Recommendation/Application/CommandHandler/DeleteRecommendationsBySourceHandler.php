<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\DeleteRecommendationsBySourceCommand;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DeleteRecommendationsBySourceHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DeleteRecommendationsBySourceCommand $command): void
    {
        $this->recommendationRepository->deleteBySource(
            $command->getSourceType(),
            $command->getSourceId(),
        );
    }
}
