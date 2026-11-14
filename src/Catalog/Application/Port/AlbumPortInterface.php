<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface AlbumPortInterface
{
    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Album;

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Album;

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    public function countVisible(LibraryReadScope $scope): int;

    /** @return array{0: Album, 1: array<int, \App\Catalog\Domain\Model\Song>}|null */
    public function findVisibleWithSongs(Uuid $uuid, LibraryReadScope $scope): ?array;

    /** @return array<int, array{name: string, role: string|null}> */
    public function getVisibleArtistNamesForAlbum(Uuid $albumId, LibraryReadScope $scope): array;

    public function findByPublicId(PublicId $publicId): ?Album;

    public function findByUuid(Uuid $uuid): ?Album;

    /**
     * Re-reads the album from the database, replacing any state this process holds for it, so a
     * decision taken after slow work sees edits other processes committed meanwhile.
     */
    public function findFreshByUuid(Uuid $uuid): ?Album;

    public function findByMbid(?MusicbrainzId $mbid): ?Album;

    public function findByMbidAndLibrary(?MusicbrainzId $mbid, Uuid $libraryId): ?Album;

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Album;

    /**
     * @return Album[]
     */
    public function findByLibrary(Uuid $libraryId): array;

    /**
     * @return array{0: Album, 1: mixed[]}|null
     */
    public function findWithSongs(Uuid $uuid): ?array;

    public function search(SearchOptions $options): SearchResult;

    public function count(): int;

    public function countCoverlessAlbums(): int;

    /**
     * @return Uuid[]
     */
    public function findCoverlessAlbumIds(int $limit = 500, int $offset = 0): array;

    public function save(Album $album): void;

    public function persist(Album $album): void;

    public function flush(): void;

    /**
     * Deletes the album and, through the database, its songs. With $deleteCover the cover image is
     * deleted after the album; the songs' audio files are never touched here.
     */
    public function delete(Album $album, bool $deleteCover = true): void;

    /**
     * Gives the album one primary artist credit when it has songs and no artist credit of any
     * role, in a transaction of its own that locks the album's row, so imports deciding the same
     * album at once leave one credit. An existing credit is never replaced or removed.
     *
     * The tagged album artist, when given, resolves to an artist as a song's tagged artist does and
     * is credited under $creditedName, or under its tagged name when that is null. Without one, the
     * album takes the artist every one of its songs has a primary credit for, under the credited
     * name all those credits share, else under the artist's own name. An album with a song that has
     * no primary credit, or whose songs credit more than one artist, gets no credit.
     *
     * Read the tags before calling it: the album's row stays locked until the credit is written.
     */
    public function creditArtistIfUncredited(Uuid $albumId, ?ArtistIdentity $albumArtist = null, ?string $creditedName = null): void;

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
