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

    #[AsMessageHandler]
    public function __invoke(SaveRecommendationCommand $command): Recommendation
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

        $this->recommendationRepository->save($recommendation);

        return $recommendation;
    }
}
