<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\SaveRecommendationCommand;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SaveRecommendationHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
    ) {
    }

    /**
     * @return bool whether the row was stored; false when a member's row or another run's holds
     *              its source, target, name and user
     */
    #[AsMessageHandler]
    public function __invoke(SaveRecommendationCommand $command): bool
    {
        $recommendation = Recommendation::create(
            sourceType: (string) $command->getSourceType(),
            sourceId: $command->getSourceId(),
            targetType: (string) $command->getTargetType(),
            targetId: $command->getTargetId(),
            score: $command->getScore(),
            userId: $command->getUserId(),
            name: $command->getName(),
            position: $command->getPosition(),
        );

        return $this->recommendationRepository->insertUnlessStored($recommendation);
    }
}
