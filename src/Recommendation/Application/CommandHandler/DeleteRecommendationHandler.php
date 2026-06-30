<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\DeleteRecommendationCommand;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DeleteRecommendationHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DeleteRecommendationCommand $command): void
    {
        $this->recommendationRepository->delete($command->getRecommendation());
    }
}
