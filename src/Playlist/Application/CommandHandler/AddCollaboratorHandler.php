<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddCollaboratorCommand;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adds a collaborator to the aggregate and saves it, so the repository's sync is the
 * only writer of the collaborator rows. Adding a user who already collaborates writes nothing.
 */
final class AddCollaboratorHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
        private readonly PlaylistCollaboratorResolverInterface $users,
    ) {
    }

    /**
     * @throws NotFoundException     when no user has the public id
     * @throws InvalidInputException when the public id is the owner's own
     */
    #[AsMessageHandler]
    public function __invoke(AddCollaboratorCommand $command): void
    {
        $userId = $this->users->userId($command->getUserPublicId());

        $playlist = $this->playlistRepository->findByUuid($command->getPlaylistId());
        if ($playlist === null) {
            throw new RuntimeException(sprintf('Playlist %s not found.', $command->getPlaylistId()->toString()));
        }

        if ($userId->equals($playlist->getUserId())) {
            throw new InvalidInputException(
                'The playlist owner cannot be added as a collaborator.',
                ['userPublicId' => $command->getUserPublicId()->toString()],
            );
        }
        if ($playlist->isCollaborator($userId)) {
            return;
        }

        $playlist->addCollaborator($userId);
        $this->playlistRepository->save($playlist);
    }
}
