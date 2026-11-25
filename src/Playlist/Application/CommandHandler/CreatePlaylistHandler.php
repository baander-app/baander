<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\CreatePlaylistCommand;
use App\Playlist\Application\Exception\PlaylistLimitReachedException;
use App\Playlist\Application\PlaylistLimits;
use App\Playlist\Domain\Event\PlaylistCreated;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreatePlaylistHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws PlaylistLimitReachedException when the owner already keeps the most playlists allowed
     */
    #[AsMessageHandler]
    public function __invoke(CreatePlaylistCommand $command): Playlist
    {
        $playlist = Playlist::create(
            $command->getName(),
            $command->getUserId(),
            $command->getDescription(),
            $command->isPublic(),
            $command->isCollaborative(),
            $command->isSmart(),
            $command->getSmartRules(),
        );

        // The owner's lock makes concurrent creates count in turn, so none passes the cap.
        $this->transaction->run(function () use ($playlist): void {
            $this->playlistRepository->lockOwnerPlaylists($playlist->getUserId());
            if ($this->playlistRepository->countOwnedBy($playlist->getUserId()) >= PlaylistLimits::PLAYLISTS_PER_OWNER) {
                throw PlaylistLimitReachedException::playlists();
            }

            $this->playlistRepository->save($playlist);
        });

        $this->eventDispatcher->dispatch(new PlaylistCreated(
            playlistId: $playlist->getId(),
            name: $playlist->getName(),
            isPublic: $command->isPublic(),
            userId: $command->getUserId(),
        ));

        return $playlist;
    }
}
