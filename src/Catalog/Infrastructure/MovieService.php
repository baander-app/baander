<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;

final class MovieService implements MoviePortInterface
{
    public function __construct(
        private readonly MovieRepositoryInterface $movieRepository,
    ) {
    }

    public function findByPublicId(PublicId $publicId): ?Movie
    {
        return $this->movieRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Movie
    {
        return $this->movieRepository->findByUuid($uuid);
    }

    public function findByLibrary(Uuid $libraryId): array
    {
        return $this->movieRepository->findByLibrary($libraryId);
    }

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Movie
    {
        return $this->movieRepository->findByTitleAndLibrary($title, $libraryId);
    }

    public function findByTmdbId(?int $tmdbId): ?Movie
    {
        return $this->movieRepository->findByTmdbId($tmdbId);
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->movieRepository->search($options);
    }

    public function count(): int
    {
        return $this->movieRepository->count();
    }

    public function save(Movie $movie): void
    {
        $this->movieRepository->save($movie);
    }

    public function persist(Movie $movie): void
    {
        $this->movieRepository->persist($movie);
    }

    public function flush(): void
    {
        $this->movieRepository->flush();
    }

    public function delete(Movie $movie): void
    {
        $this->movieRepository->delete($movie);
    }
}
