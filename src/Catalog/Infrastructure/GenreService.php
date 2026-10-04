<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Catalog\Domain\Repository\GenreRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreMovieRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final class GenreService implements GenrePortInterface
{
    public function __construct(
        private readonly GenreRepositoryInterface $genreRepository,
        private readonly GenreMovieRepository $genreMovieRepository,
    ) {
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?GenreReadView
    {
        return $this->genreRepository->findVisibleByUuid($uuid, $scope);
    }

    public function findVisibleBySlug(string $slug, LibraryReadScope $scope): ?GenreReadView
    {
        return $this->genreRepository->findVisibleBySlug($slug, $scope);
    }

    /** @return GenreReadView[] */
    public function findVisibleChildren(Uuid $parentId, LibraryReadScope $scope): array
    {
        return $this->genreRepository->findVisibleChildren($parentId, $scope);
    }

    /** @return GenreReadView[] */
    public function findVisibleRootGenres(LibraryReadScope $scope): array
    {
        return $this->genreRepository->findVisibleRootGenres($scope);
    }

    /** @return GenreReadView[] */
    public function findAllVisible(LibraryReadScope $scope): array
    {
        return $this->genreRepository->findAllVisible($scope);
    }

    public function countVisible(LibraryReadScope $scope): int
    {
        return $this->genreRepository->countVisible($scope);
    }

    public function findByUuid(Uuid $uuid): ?Genre
    {
        return $this->genreRepository->findByUuid($uuid);
    }

    public function findBySlug(string $slug): ?Genre
    {
        return $this->genreRepository->findBySlug($slug);
    }

    /**
     * @return Genre[]
     */
    public function findChildren(Uuid $parentId): array
    {
        return $this->genreRepository->findChildren($parentId);
    }

    /**
     * @return Genre[]
     */
    public function findRootGenres(): array
    {
        return $this->genreRepository->findRootGenres();
    }

    public function findOrCreateByName(string $name): Genre
    {
        return $this->genreRepository->findOrCreateByName($name);
    }

    public function findAll(): array
    {
        return $this->genreRepository->findAll();
    }

    public function isDescendantOf(Uuid $parentId, Uuid $childId): bool
    {
        return $this->genreRepository->isDescendantOf($parentId, $childId);
    }

    public function count(): int
    {
        return $this->genreRepository->count();
    }

    public function save(Genre $genre): void
    {
        $this->genreRepository->save($genre);
    }

    public function persist(Genre $genre): void
    {
        $this->genreRepository->persist($genre);
    }

    public function flush(): void
    {
        $this->genreRepository->flush();
    }

    public function delete(Genre $genre): void
    {
        $this->genreRepository->delete($genre);
    }

    public function addSongToGenre(Uuid $genreId, Uuid $songId): void
    {
        $this->genreRepository->addSongToGenre($genreId, $songId);
    }

    public function removeSongFromGenre(Uuid $genreId, Uuid $songId): void
    {
        $this->genreRepository->removeSongFromGenre($genreId, $songId);
    }

    public function addAlbumToGenre(Uuid $genreId, Uuid $albumId): void
    {
        $this->genreRepository->addAlbumToGenre($genreId, $albumId);
    }

    public function removeAlbumFromGenre(Uuid $genreId, Uuid $albumId): void
    {
        $this->genreRepository->removeAlbumFromGenre($genreId, $albumId);
    }

    public function addMovieToGenre(Uuid $genreId, Uuid $movieId): void
    {
        $this->genreMovieRepository->add($genreId, $movieId);
    }

    public function removeMovieFromGenre(Uuid $genreId, Uuid $movieId): void
    {
        $this->genreMovieRepository->remove($genreId, $movieId);
    }
}
