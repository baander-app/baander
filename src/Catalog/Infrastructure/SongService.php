<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;

final class SongService implements SongPortInterface
{
    public function __construct(
        private readonly SongRepositoryInterface $songRepository,
        private readonly StoragePortInterface $storage,
    ) {
    }

    public function findByPublicId(PublicId $publicId): ?Song
    {
        return $this->songRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Song
    {
        return $this->songRepository->findByUuid($uuid);
    }

    public function findByPath(string $path): ?Song
    {
        return $this->songRepository->findByPath($path);
    }

    public function findByHash(string $hash): ?Song
    {
        return $this->songRepository->findByHash($hash);
    }

    /**
     * @return Song[]
     */
    public function findByAlbum(Uuid $albumId, int $limit = 100): array
    {
        return $this->songRepository->findByAlbum($albumId, $limit);
    }

    /**
     * @return Song[]
     */
    public function findByAlbumSortedByTrack(Uuid $albumId): array
    {
        return $this->songRepository->findByAlbumSortedByTrack($albumId);
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->songRepository->search($options);
    }

    public function searchWithCursor(SearchOptions $options): CursorPage
    {
        return $this->songRepository->searchWithCursor($options);
    }

    public function count(): int
    {
        return $this->songRepository->count();
    }

    public function countByAlbum(Uuid $albumId): int
    {
        return $this->songRepository->countByAlbum($albumId);
    }

    public function save(Song $song): void
    {
        $this->songRepository->save($song);
    }

    public function persist(Song $song): void
    {
        $this->songRepository->persist($song);
    }

    public function flush(): void
    {
        $this->songRepository->flush();
    }

    public function delete(Song $song, bool $deleteFile = false): void
    {
        if ($deleteFile) {
            $this->storage->delete($song->getPath());
        }

        $this->songRepository->delete($song);
    }

    public function linkArtistToSong(Uuid $songId, string $artistName, string $role): void
    {
        $this->songRepository->linkArtistToSong($songId, $artistName, $role);
    }

    public function getArtistNameForSong(Uuid $songId): ?string
    {
        return $this->songRepository->getArtistNameForSong($songId);
    }

    public function getArtistNamesForSongs(array $songIds): array
    {
        return $this->songRepository->getArtistNamesForSongs($songIds);
    }

    public function getAlbumTitlesByIds(array $albumIds): array
    {
        return $this->songRepository->getAlbumTitlesByIds($albumIds);
    }

    public function findByUuids(array $uuids): array
    {
        return $this->songRepository->findByUuids($uuids);
    }
}
