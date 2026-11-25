<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddCollaboratorCommand;
use App\Playlist\Application\Exception\PlaylistLimitReachedException;
use App\Playlist\Application\PlaylistLimits;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Playlist\Application\Exception\PlaylistWriteConflictException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
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
     * @throws NotFoundException             when no user has the public id
     * @throws InvalidInputException         when the public id is the owner's own
     * @throws PlaylistLimitReachedException when the playlist already has the most collaborators allowed
     */
    #[AsMessageHandler]
    public function __invoke(AddCollaboratorCommand $command): void
    {
        $userId = null;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $playlist = $this->playlistRepository->findByUuid($command->getPlaylistId());
            if ($playlist === null) {
                throw new NotFoundException('Playlist not found.');
            }
            if (!$playlist->getUserId()->equals($command->getActorUuid())) {
                throw new AccessDeniedException('Only the owner may manage the collaborators of this playlist.');
            }

            $userId ??= $this->users->userId($command->getUserPublicId());
            if ($userId->equals($playlist->getUserId())) {
                throw new InvalidInputException(
                    'The playlist owner cannot be added as a collaborator.',
                    ['userPublicId' => $command->getUserPublicId()->toString()],
                );
            }
            if ($playlist->isCollaborator($userId)) {
                return;
            }
            // The save compares the collaborators it read with the rows, so a concurrent
            // addition conflicts and the retry counts again.
            if (count($playlist->collaboratorIds()) >= PlaylistLimits::COLLABORATORS_PER_PLAYLIST) {
                throw PlaylistLimitReachedException::collaborators();
            }

            $playlist->addCollaborator($userId);
            try {
                $this->playlistRepository->save($playlist);
                return;
            } catch (PlaylistWriteConflictException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
    }
}
