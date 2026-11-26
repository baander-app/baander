<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\DeleteGeneratedRecommendationsBySourceCommand;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DeleteGeneratedRecommendationsBySourceHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DeleteGeneratedRecommendationsBySourceCommand $command): void
    {
        $this->recommendationRepository->deleteGeneratedBySource($command->sourceType, $command->sourceId, $command->userId);
    }
}
