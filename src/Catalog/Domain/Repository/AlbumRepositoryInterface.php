<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\Repository\Searchable;

interface AlbumRepositoryInterface extends Searchable
{
    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Album;

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Album;

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    public function countVisible(LibraryReadScope $scope): int;

    /** @return array{0: Album, 1: array<int, Song>}|null */
    public function findVisibleWithSongs(Uuid $uuid, LibraryReadScope $scope): ?array;

    /** @return array<int, array{name: string, role: string|null}> */
    public function getVisibleArtistNamesForAlbum(Uuid $albumId, LibraryReadScope $scope): array;

    public function save(Album $album): void;

    public function persist(Album $album): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?Album;

    /**
     * Re-reads the album from the database, replacing any state this process holds for it, so a
     * decision taken after slow work sees edits other processes committed meanwhile.
     */
    public function findFreshByUuid(Uuid $uuid): ?Album;

    public function findByPublicId(PublicId $publicId): ?Album;

    public function findByMbid(?MusicbrainzId $mbid): ?Album;

    public function findByMbidAndLibrary(?MusicbrainzId $mbid, Uuid $libraryId): ?Album;

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Album;

    /**
     * @return Album[]
     */
    public function findByLibrary(Uuid $libraryId): array;

    /**
     * @return array{0: Album, 1: array<int, Song>}|null Returns [Album, Song[]] or null
     */
    public function findWithSongs(Uuid $uuid): ?array;

    public function count(): int;

    public function countCoverlessAlbums(): int;

    /**
     * @return Uuid[]
     */
    public function findCoverlessAlbumIds(int $limit = 500, int $offset = 0): array;

    /**
     * @return list<Uuid>
     */
    public function findCoverlessAlbumIdsAfter(?Uuid $after = null, int $limit = 500): array;

    /**
     * @return Uuid[]
     */
    public function findCoverlessAlbumIdsByLibrary(Uuid $libraryId): array;

    /**
     * @return Uuid[]
     */
    public function findAlbumIdsByLibrary(Uuid $libraryId): array;

    public function delete(Album $album): void;

    public function linkArtistToAlbum(Uuid $albumId, string $artistName, string $role): void;

    /**
     * @return array<int, array{name: string, role: string|null}>
     */
    public function getArtistNamesForAlbum(Uuid $albumId): array;

    /**
     * @param Uuid[] $albumIds
     * @return array<string, array<int, array{name: string, role: string|null}>> keyed by album UUID string
     */
    public function getArtistNamesForAlbums(array $albumIds): array;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Album> keyed by UUID string
     */
    public function findByUuids(array $uuids): array;
}
