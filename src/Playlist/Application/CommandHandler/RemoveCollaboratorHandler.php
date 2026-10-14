<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\RemoveCollaboratorCommand;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes a collaborator from the aggregate and saves it, so the repository's sync drops
 * the row. Removing a user who does not collaborate writes nothing.
 */
final class RemoveCollaboratorHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
        private readonly PlaylistCollaboratorResolverInterface $users,
    ) {
    }

    /**
     * @throws NotFoundException when no user has the public id
     */
    #[AsMessageHandler]
    public function __invoke(RemoveCollaboratorCommand $command): void
    {
        $userId = $this->users->userId($command->getUserPublicId());

        $playlist = $this->playlistRepository->findByUuid($command->getPlaylistId());
        if ($playlist === null) {
            throw new RuntimeException(sprintf('Playlist %s not found.', $command->getPlaylistId()->toString()));
        }

        if (!$playlist->isCollaborator($userId)) {
            return;
        }

        $playlist->removeCollaborator($userId);
        $this->playlistRepository->save($playlist);
    }
}
