<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Repository\Searchable;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface MovieRepositoryInterface extends Searchable
{
    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Movie;

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Movie;

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    public function countVisible(LibraryReadScope $scope): int;

    /**
     * @param list<Uuid> $ids
     *
     * @return list<Movie> Accessible movies in the supplied ID order, at most 100
     */
    public function findVisibleByUuids(array $ids, LibraryReadScope $scope): array;

    /** @return list<Uuid> At most 100 IDs in ascending order */
    public function movieIdsByLibraryAfter(Uuid $libraryId, ?Uuid $after = null, int $limit = 100): array;

    public function save(Movie $movie): void;

    /** Updates an existing movie's provider metadata while preserving its other state. */
    public function saveMetadata(Movie $movie): void;

    /** Updates only an existing movie's matching provider, identifiers and manual choice. */
    public function saveMatchIdentity(Movie $movie): void;

    public function persist(Movie $movie): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?Movie;

    public function findByPublicId(PublicId $publicId): ?Movie;

    /**
     * @return Movie[]
     */
    public function findByLibrary(Uuid $libraryId): array;

    /** A root-level file uses its relative filename as the folder identity. */
    public function findByPlacementFolder(Uuid $libraryId, string $folderIdentity): ?Movie;

    /** Adds a feature version without rewriting the movie's metadata or other versions. */
    public function addVideoForImport(Uuid $movieId, Uuid $videoId): void;

    /** Removes a feature link when its placement is classified as an extra. */
    public function removeVideoForImport(Uuid $movieId, Uuid $videoId): void;

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Movie;

    public function findByTmdbId(?int $tmdbId): ?Movie;

    public function count(): int;

    public function delete(Movie $movie): void;
}
