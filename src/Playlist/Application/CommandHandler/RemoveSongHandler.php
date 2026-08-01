<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\RemoveSongCommand;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RemoveSongHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RemoveSongCommand $command): Playlist
    {
        $playlist = $this->playlistRepository->findWithSongs($command->getPlaylistId());

        if ($playlist === null) {
            throw new RuntimeException(sprintf('Playlist %s not found.', $command->getPlaylistId()->toString()));
        }

        $playlist->removeSong($command->getSongId());
        $this->playlistRepository->save($playlist);

        return $playlist;
    }
}
