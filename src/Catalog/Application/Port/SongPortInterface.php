<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface SongPortInterface
{
    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Song;

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Song;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Song> keyed by UUID string
     */
    public function findVisibleByUuids(array $uuids, LibraryReadScope $scope): array;

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    public function countVisible(LibraryReadScope $scope): int;

    /**
     * The sort field, when set, must be a {@see SongSortField} value.
     *
     * @throws \App\Shared\Domain\Exception\CursorMismatchException when the cursor was issued for another sort field or order
     */
    public function searchVisibleWithCursor(SearchOptions $options, LibraryReadScope $scope): CursorPage;

    /**
     * @param Uuid[] $songIds
     * @return array<string, string>
     */
    public function getVisibleArtistNamesForSongs(array $songIds, LibraryReadScope $scope): array;

    /**
     * Titles and stored languages of the albums the scope can read; albums outside it are absent.
     *
     * @param Uuid[] $albumIds
     * @return array<string, array{title: string, language: string|null}> albumUuid => title and stored language
     */
    public function getVisibleAlbumTitlesAndLanguagesByIds(array $albumIds, LibraryReadScope $scope): array;

    public function findByPublicId(PublicId $publicId): ?Song;

    public function findByUuid(Uuid $uuid): ?Song;

    /**
     * Re-reads the song from the database, replacing any state this process holds for it, so a
     * decision taken after slow work sees edits other processes committed meanwhile.
     */
    public function findFreshByUuid(Uuid $uuid): ?Song;

    /**
     * Locks what a retag of these songs decides on, inside the caller's transaction and in the
     * order every catalog writer takes its locks: first the import locks of every artist identity
     * and genre the transaction may find or create, in one sorted pass, then the rows of the songs
     * in ID order. A play, favorite or credit recorded for one of the songs meanwhile does not wait.
     * Read the songs again after this call to see what a writer that held a lock first committed.
     *
     * The artist and genre lookups that run later in the transaction then take only locks it holds
     * already, so they never wait while it holds a song row.
     *
     * @param list<ArtistIdentity> $artists every artist identity the transaction may resolve
     * @param list<string>         $genres  every tagged genre name the transaction may find or create
     * @param list<Uuid>           $songIds
     *
     * @throws \LogicException outside a transaction, before taking any lock
     */
    public function lockForRetag(array $artists, array $genres, array $songIds): void;

    /**
     * Takes the import's advisory locks of these artist identities and genres in one sorted pass,
     * inside the caller's transaction: the tagged name of each artist, its MusicBrainz ID when it
     * has one, and the slug of each genre. They hold until the transaction ends. A key the
     * transaction holds already is granted again at once, so the artist resolver and the genre
     * lookup may take any of these keys later. Take every key the transaction needs in this one
     * call: a later call could take a key that sorts before a held one and deadlock.
     *
     * @param list<ArtistIdentity> $artists
     * @param list<string>         $genres tagged genre names
     *
     * @throws \LogicException outside a transaction, before taking any lock
     */
    public function lockImportIdentities(array $artists, array $genres): void;

    /**
     * Locks the rows of these songs FOR NO KEY UPDATE, inside the caller's transaction and in ID
     * order, after the import identity locks. A play, favorite or credit recorded for one of the
     * songs meanwhile does not wait. Read the songs again after this call, with findFreshByUuid(),
     * to see what a writer that held a lock first committed.
     *
     * @param list<Uuid> $songIds
     *
     * @throws \LogicException outside a transaction, before locking anything
     */
    public function lockInIdOrder(array $songIds): void;

    /**
     * The songs of the library stored at these paths, whatever the availability of their files.
     * Where several songs share a path, the oldest is returned.
     *
     * @param list<string> $paths
     * @return array<string, Song> keyed by path; a path without a song is absent
     */
    public function findByPathsInLibrary(Uuid $libraryId, array $paths): array;

    public function findByHash(string $hash): ?Song;

    /**
     * @return Song[]
     */
    public function findByAlbum(Uuid $albumId, int $limit = 100): array;

    /**
     * @return Song[]
     */
    public function findByAlbumSortedByTrack(Uuid $albumId): array;

    /**
     * The IDs of every song these albums hold, in one statement and without a limit.
     *
     * @param list<Uuid> $albumIds
     *
     * @return list<Uuid> in ID order
     */
    public function findIdsByAlbums(array $albumIds): array;

    /**
     * Re-reads these songs from the database, replacing any state this process holds for them, so
     * a writer that locked them sees what another writer committed first. A song that no longer
     * exists is absent.
     *
     * @param list<Uuid> $uuids
     *
     * @return array<string, Song> keyed by UUID string, in ID order
     */
    public function findFreshByUuids(array $uuids): array;

    public function search(SearchOptions $options): SearchResult;

    public function searchWithCursor(SearchOptions $options): CursorPage;

    public function count(): int;

    public function countByAlbum(Uuid $albumId): int;

    public function save(Song $song): void;

    public function persist(Song $song): void;

    public function flush(): void;

    /** Deletes the song; its audio file is never touched here. */
    public function delete(Song $song): void;

    /**
     * Credits the artist on the song under the name the song was tagged with; the next flush writes
     * it. The song may be one persisted in this unit of work and not flushed yet. A song holds at
     * most one credit per artist and role, so the song must not hold this one already.
     *
     * @throws \RuntimeException when the song or the artist does not exist
     */
    public function addArtistCredit(Uuid $songId, Uuid $artistId, string $role, string $creditedName): void;

    /**
     * Deletes every artist credit of the song, of any role, at once rather than on the next flush,
     * so a credit added afterwards may repeat a removed credit's artist and role. A credit added in
     * this unit of work and not flushed yet is not deleted.
     */
    public function removeArtistCredits(Uuid $songId): void;

    /**
     * Replaces the song's primary and featured artist credits with these, writing only the credits
     * that differ; credits of every other role stay. Deletes and renames run at once, inserts on
     * the next flush.
     *
     * @param list<array{artistId: Uuid, role: ArtistRole, creditedName: string}> $credits the first of each artist and role counts
     *
     * @return bool whether a credit changed
     *
     * @throws \RuntimeException when the song or a credited artist does not exist
     */
    public function replacePerformerCredits(Uuid $songId, array $credits): bool;

    /**
     * Which of these songs have at least one artist credit, of any role. Reads committed credits
     * only, not credits added in this unit of work and not flushed yet.
     *
     * @param list<Uuid> $songIds
     * @return list<string> the UUID of each credited song
     */
    public function findCreditedSongIds(array $songIds): array;

    public function getArtistNameForSong(Uuid $songId): ?string;

    /**
     * @param Uuid[] $songIds
     * @return array<string, string> songUuid => artistName
     */
    public function getArtistNamesForSongs(array $songIds): array;

    /**
     * @param Uuid[] $albumIds
     * @return array<string, array{title: string, language: string|null}> albumUuid => title and stored language
     */
    public function getAlbumTitlesAndLanguagesByIds(array $albumIds): array;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Song> keyed by UUID string
     */
    public function findByUuids(array $uuids): array;
}
