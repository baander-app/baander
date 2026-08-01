<?php

declare(strict_types=1);

namespace App\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\UpdatePlaylistCommand;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class UpdatePlaylistHandler
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(UpdatePlaylistCommand $command): Playlist
    {
        $playlist = $this->playlistRepository->findByUuid($command->getPlaylistId());

        if ($playlist === null) {
            throw new RuntimeException(sprintf('Playlist %s not found.', $command->getPlaylistId()->toString()));
        }

        $playlist->updateMetadata(
            $command->getName(),
            $command->getDescription(),
            $command->isPublic(),
        );

        $this->playlistRepository->save($playlist);

        return $playlist;
    }
}
