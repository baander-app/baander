<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface GenreRepositoryInterface
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

    public function save(Genre $genre): void;

    public function persist(Genre $genre): void;

    public function flush(): void;

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

    public function findOrCreateByName(string $name): Genre;

    /**
     * @return Genre[]
     */
    public function findAll(): array;

    public function isDescendantOf(Uuid $parentId, Uuid $childId): bool;

    public function count(): int;

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
