<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Repository\Searchable;

interface MovieRepositoryInterface extends Searchable
{
    public function save(Movie $movie): void;

    public function persist(Movie $movie): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?Movie;

    public function findByPublicId(PublicId $publicId): ?Movie;

    public function findByLibrary(Uuid $libraryId): array;

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Movie;

    public function findByTmdbId(?int $tmdbId): ?Movie;

    public function count(): int;

    public function delete(Movie $movie): void;
}
