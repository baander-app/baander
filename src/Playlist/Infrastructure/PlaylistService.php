<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure;

use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class PlaylistService implements PlaylistPortInterface
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlistRepository,
    ) {
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
