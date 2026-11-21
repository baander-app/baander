<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\DirectorPortInterface;
use App\Catalog\Application\Port\DirectorCredit;
use App\Catalog\Domain\ValueObject\DirectorCredit as DomainDirectorCredit;
use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Catalog\Domain\Repository\DirectorRepositoryInterface;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final readonly class DirectorService implements DirectorPortInterface
{
    public function __construct(
        private DirectorRepositoryInterface $directors,
        private MovieRepositoryInterface $movies,
    ) {}

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        return $this->directors->searchVisible($options, $scope);
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?DirectorReadView
    {
        return $this->directors->findVisibleByPublicId($publicId, $scope);
    }

    public function searchMoviesVisible(PublicId $publicId, SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        $result = $this->directors->searchMovieIdsVisible($publicId, $options, $scope);
        $movies = $this->movies->findVisibleByUuids($result->getItems(), $scope);

        return SearchResult::create($movies, $result->getTotal());
    }

    public function syncMovieDirectors(Uuid $movieId, array $credits, bool $forceUpdate = false): bool
    {
        $values = array_map(static fn(DirectorCredit $credit): DomainDirectorCredit => new DomainDirectorCredit(
            $credit->tmdbId,
            $credit->name,
            $credit->profileUrl,
        ), $credits);

        return $this->directors->syncMovieDirectors($movieId, $values, $forceUpdate);
    }
}
