<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\CreatePlaylistCommand;
use App\Playlist\Domain\Event\PlaylistCreated;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreatePlaylistHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreatePlaylistCommand $command): Playlist
    {
        $playlist = Playlist::create(
            $command->getName(),
            $command->getUserId(),
            $command->getDescription(),
            $command->isPublic(),
        );

        $this->playlistRepository->save($playlist);

        $this->eventDispatcher->dispatch(new PlaylistCreated(
            playlistId: $playlist->getId(),
            name: $playlist->getName(),
            isPublic: $command->isPublic(),
            userId: $command->getUserId(),
        ));

        return $playlist;
    }
}
