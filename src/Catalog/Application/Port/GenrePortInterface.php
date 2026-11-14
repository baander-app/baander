<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface GenrePortInterface
{
    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?GenreReadView;

    public function findVisibleBySlug(string $slug, LibraryReadScope $scope): ?GenreReadView;

    /** @return GenreReadView[] */
    public function findVisibleChildren(Uuid $parentId, LibraryReadScope $scope): array;

    /** @return GenreReadView[] */
    public function findVisibleRootGenres(LibraryReadScope $scope): array;

    /** @return GenreReadView[] */
    public function findAllVisible(LibraryReadScope $scope): array;

    public function countVisible(LibraryReadScope $scope): int;

    public function findByPublicId(PublicId $publicId): ?Genre;

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?GenreReadView;

    public function findByUuid(Uuid $uuid): ?Genre;

    public function findBySlug(string $slug): ?Genre;

    /**
     * @return Genre[]
     */
    public function findChildren(Uuid $parentId): array;

    /**
     * @return Genre[]
     */
    public function findRootGenres(): array;

    /**
     * Finds the genre a tag names, or creates it, outside any import. It takes no import lock, so
     * an import creating the same genre at once can fail the call, which closes the entity manager.
     */
    public function findOrCreateByName(string $name): Genre;

    /**
     * Finds or creates the genres an import batch tags, inside the caller's transaction, so
     * imports running at the same time never create two genres for one slug. Names that share a
     * slug, such as "rock" and "Rock" or "Hip-Hop" and "Hip Hop", resolve to one genre.
     *
     * It locks the slug of every name until that transaction ends, in one sorted pass, then reads
     * the genres as committed after the locks: the genre with the slug, else the genre whose name
     * equals the tag ignoring case. A genre it creates takes the first name seen for its slug, an
     * ASCII first letter capitalized, and is flushed before it returns. That flush writes every pending
     * change of the unit of work, so call it before persisting the songs that use the result.
     *
     * Call it once per transaction, with every genre the transaction writes, after the artist
     * identities: genre keys sort after artist keys, and a second call could take a key that sorts
     * before one already held and deadlock with a transaction taking both in order.
     *
     * @param list<string> $names
     *
     * @return array<string, Uuid> the genre of each name, keyed by the name as given
     *
     * @throws \LogicException outside a transaction, before locking or writing anything
     */
    public function findOrCreateForImport(array $names): array;

    /**
     * @return Genre[]
     */
    public function findAll(): array;

    public function isDescendantOf(Uuid $parentId, Uuid $childId): bool;

    public function count(): int;

    public function save(Genre $genre): void;

    public function persist(Genre $genre): void;

    public function flush(): void;

    public function delete(Genre $genre): void;

    /** @return bool false, changing nothing, when the genre or the song does not exist */
    public function addSongToGenre(Uuid $genreId, Uuid $songId): bool;

    /** @return bool false, changing nothing, when the genre or the song does not exist */
    public function removeSongFromGenre(Uuid $genreId, Uuid $songId): bool;

    /** @return bool false, changing nothing, when the genre or the album does not exist */
    public function addAlbumToGenre(Uuid $genreId, Uuid $albumId): bool;

    /** @return bool false, changing nothing, when the genre or the album does not exist */
    public function removeAlbumFromGenre(Uuid $genreId, Uuid $albumId): bool;

    public function addMovieToGenre(Uuid $genreId, Uuid $movieId): void;

    public function removeMovieFromGenre(Uuid $genreId, Uuid $movieId): void;
}
