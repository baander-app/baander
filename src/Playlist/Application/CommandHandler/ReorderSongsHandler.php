<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\ReorderSongsCommand;
use App\Playlist\Application\Exception\PlaylistWriteConflictException;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class ReorderSongsHandler
{
    public function __construct(private PlaylistRepositoryInterface $playlistRepository)
    {
    }

    #[AsMessageHandler]
    public function __invoke(ReorderSongsCommand $command): string
    {
        $playlist = $this->playlistRepository->findByUuid($command->playlistId);
        if ($playlist === null) {
            throw new NotFoundException('Playlist not found.');
        }
        if (!$playlist->getUserId()->equals($command->actorUuid)
            && !($playlist->isCollaborative() && $playlist->isCollaborator($command->actorUuid))) {
            throw new AccessDeniedException('Only the owner, or a collaborator while the playlist is collaborative, may change its songs.');
        }
        if ($playlist->getRevision() !== $command->expectedRevision) {
            throw new PlaylistWriteConflictException();
        }

        $playlist->reorderSongs($command->songIds);
        $this->playlistRepository->save($playlist);

        return $playlist->getRevision();
    }
}
