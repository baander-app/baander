<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddSongCommand;
use App\Playlist\Application\Port\PlaylistSongVisibilityInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AddSongHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
        private readonly PlaylistSongVisibilityInterface $songVisibility,
    ) {
    }

    /**
     * @throws NotFoundException when the song does not exist or the acting user cannot see it
     */
    #[AsMessageHandler]
    public function __invoke(AddSongCommand $command): Playlist
    {
        $playlist = $this->playlistRepository->findByUuid($command->getPlaylistId());

        if ($playlist === null) {
            throw new NotFoundException('Playlist not found.');
        }

        if (!$playlist->getUserId()->equals($command->getActorUuid())
            && !($playlist->isCollaborative() && $playlist->isCollaborator($command->getActorUuid()))) {
            throw new AccessDeniedException('Only the owner, or a collaborator while the playlist is collaborative, may change its songs.');
        }

        if (!$this->songVisibility->isVisible($command->getSongId(), $command->getScope())) {
            throw new NotFoundException('Song not found.');
        }

        // Idempotent: if the song already exists in the playlist, return as-is
        if (array_any($playlist->getSongs(), fn($song) => $song->getSongId()->equals($command->getSongId()))) {
            return $playlist;
        }

        $playlist->appendSong($command->getSongId());
        $this->playlistRepository->save($playlist);

        return $playlist;
    }
}
