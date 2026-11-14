<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\ArtistRole;
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

    /**
     * Locks the album's row until the transaction ends, so the writers deciding its artist credit
     * take turns, then reads what the decision needs as committed when the lock was granted. Songs
     * and credits added to the album meanwhile do not wait for the lock. Call it inside a
     * transaction.
     *
     * @return array<string, list<array{artistId: Uuid, artistName: string, creditedName: string}>>|null
     *         every song of the album, by song ID, with its primary credits, an empty list for a song
     *         without one; null when the album no longer exists or has an artist credit of any role
     */
    public function lockForArtistCredit(Uuid $albumId): ?array;

    /** Adds a credit of the album to the artist; the next flush writes it. */
    public function addArtistCredit(Uuid $albumId, Uuid $artistId, string $role, string $creditedName): void;

    /**
     * Locks the rows of these albums, inside the caller's transaction and in ID order, against every
     * other writer of an album until that transaction ends. Songs and credits added to an album
     * meanwhile do not wait. Albums that no longer exist are skipped. Read the albums after this
     * call, in statements of their own, to see what a writer that held a lock first committed.
     *
     * @param list<Uuid> $albumIds
     *
     * @throws \LogicException outside a transaction, before locking anything
     */
    public function lockInIdOrder(array $albumIds): void;

    /**
     * Replaces the album's primary and featured artist credits with these, writing only what
     * differs: a credit no longer given is deleted and a credit whose credited name changed takes
     * the new name at once, and a missing credit is added on the next flush. Credits of every
     * other role stay.
     *
     * @param list<array{artistId: Uuid, role: ArtistRole, creditedName: string}> $credits the first of each artist and role counts
     *
     * @return bool whether a credit changed
     *
     * @throws \RuntimeException when the album or a credited artist does not exist
     */
    public function replacePerformerCredits(Uuid $albumId, array $credits): bool;

    /**
     * Files the album under exactly these genres: links to other genres are deleted at once, and
     * missing links are added on the next flush.
     *
     * @param list<Uuid> $genreIds
     *
     * @return bool whether a link changed
     *
     * @throws \RuntimeException when the album or a genre does not exist
     */
    public function replaceGenres(Uuid $albumId, array $genreIds): bool;

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
