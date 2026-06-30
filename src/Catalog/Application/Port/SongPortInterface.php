<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Song;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;

interface SongPortInterface
{
    public function findByPublicId(PublicId $publicId): ?Song;

    public function findByUuid(Uuid $uuid): ?Song;

    public function findByPath(string $path): ?Song;

    public function findByHash(string $hash): ?Song;

    /**
     * @return Song[]
     */
    public function findByAlbum(Uuid $albumId, int $limit = 100): array;

    /**
     * @return Song[]
     */
    public function findByAlbumSortedByTrack(Uuid $albumId): array;

    public function search(SearchOptions $options): SearchResult;

    public function searchWithCursor(SearchOptions $options): CursorPage;

    public function count(): int;

    public function countByAlbum(Uuid $albumId): int;

    public function save(Song $song): void;

    public function persist(Song $song): void;

    public function flush(): void;

    public function delete(Song $song, bool $deleteFile = false): void;

    public function linkArtistToSong(Uuid $songId, string $artistName, string $role): void;

    public function getArtistNameForSong(Uuid $songId): ?string;

    /**
     * @param Uuid[] $songIds
     * @return array<string, string> songUuid => artistName
     */
    public function getArtistNamesForSongs(array $songIds): array;

    /**
     * @param Uuid[] $albumIds
     * @return array<string, string> albumUuid => albumTitle
     */
    public function getAlbumTitlesByIds(array $albumIds): array;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Song> keyed by UUID string
     */
    public function findByUuids(array $uuids): array;
}
