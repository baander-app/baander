<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure;

use App\Playlist\Application\Port\PlaylistDeletionPreviewPortInterface;
use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final class PlaylistService implements PlaylistPortInterface, PlaylistDeletionPreviewPortInterface
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
    ) {
    }

    /** @return list<PlaylistReadView> */
    public function findReadByUser(Uuid $ownerId, LibraryReadScope $scope): array
    {
        return $this->playlistRepository->findReadByUser($ownerId, $scope);
    }

    /**
     * @param list<Uuid> $songIds
     * @return list<array{uuid: string, name: string}>
     */
    public function findContainingSongs(array $songIds): array
    {
        $playlistsById = [];
        $seenSongIds = [];

        foreach ($songIds as $songId) {
            $songKey = $songId->toString();

            if (isset($seenSongIds[$songKey])) {
                continue;
            }

            $seenSongIds[$songKey] = true;

            foreach ($this->playlistRepository->findPlaylistNamesContainingSong($songId) as $playlist) {
                $playlistsById[$playlist['uuid']] = $playlist;
            }
        }

        return array_values($playlistsById);
    }

    public function save(Playlist $playlist): void
    {
        $this->playlistRepository->save($playlist);
    }

    public function findByUuid(Uuid $uuid): ?Playlist
    {
        return $this->playlistRepository->findByUuid($uuid);
    }

    public function findByPublicId(PublicId $publicId): ?Playlist
    {
        return $this->playlistRepository->findByPublicId($publicId);
    }

    /**
     * @return Playlist[]
     */
    public function findByUser(Uuid $userId): array
    {
        return $this->playlistRepository->findByUser($userId);
    }

    public function findWithSongs(Uuid $id): ?Playlist
    {
        return $this->playlistRepository->findWithSongs($id);
    }

    public function delete(Playlist $playlist): void
    {
        $this->playlistRepository->delete($playlist);
    }
}
