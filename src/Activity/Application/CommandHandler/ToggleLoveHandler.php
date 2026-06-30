<?php

declare(strict_types=1);

namespace App\Activity\Application\CommandHandler;

use App\Activity\Application\Command\ToggleLoveCommand;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for toggling the love flag on a media activity.
 */
final class ToggleLoveHandler
{
    public function __construct(
        private readonly MediaActivityRepositoryInterface $activityRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ToggleLoveCommand $command): \App\Activity\Domain\Model\MediaActivity
    {
        $activity = $this->activityRepository->findByUuid($command->getActivityId());

        if ($activity === null) {
            throw new RuntimeException('Activity not found.');
        }

        $activity->toggleLove();
        $this->activityRepository->save($activity);

        return $activity;
    }
}
