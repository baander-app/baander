<?php

declare(strict_types=1);

namespace App\Activity\Application\CommandHandler;

use App\Activity\Application\Command\RecordPlayCommand;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RecordPlayHandler
{
    public function __construct(
        private readonly MediaActivityRepositoryInterface $activityRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RecordPlayCommand $command): MediaActivity
    {
        if ($command->getMovieId() !== null && $command->getSongId() === null) {
            $activity = $this->activityRepository->findForMovie(
                $command->getUserId(),
                $command->getMovieId(),
            );
        } else {
            $activity = $this->activityRepository->findForSong(
                $command->getUserId(),
                $command->getSongId(),
            );
        }

        if ($activity === null) {
            $activity = MediaActivity::create(
                $command->getUserId(),
                'play',
                $command->getSongId(),
                $command->getAlbumId(),
                $command->getArtistId(),
                $command->getMovieId(),
            );
        }

        $activity->recordPlay($command->getPlatform(), $command->getPlayer());
        $this->activityRepository->save($activity);

        return $activity;
    }
}
