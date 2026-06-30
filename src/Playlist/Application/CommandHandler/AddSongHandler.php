<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddSongCommand;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class AddSongHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AddSongCommand $command): Playlist
    {
        $playlist = $this->playlistRepository->findWithSongs($command->getPlaylistId());

        if ($playlist === null) {
            throw new RuntimeException(sprintf('Playlist %s not found.', $command->getPlaylistId()->toString()));
        }

        // Idempotent: if the song already exists in the playlist, return as-is
        if (array_any($playlist->getSongs(), fn($song) => $song->getSongId()->equals($command->getSongId()))) {
            return $playlist;
        }

        $playlist->addSong($command->getSongId(), $command->getPosition());
        $this->playlistRepository->save($playlist);

        return $playlist;
    }
}
