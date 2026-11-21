<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Catalog\Domain\ValueObject\DirectorCredit;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface DirectorRepositoryInterface
{
    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?DirectorReadView;

    /** Returns movie UUIDs, ordered and paginated within the read scope. */
    public function searchMovieIdsVisible(PublicId $publicId, SearchOptions $options, LibraryReadScope $scope): SearchResult;

    /**
     * The caller owns the transaction that writes the movie and its credits.
     *
     * @param list<DirectorCredit> $credits
     * @return bool Whether an authoritative credit set was accepted
     */
    public function syncMovieDirectors(Uuid $movieId, array $credits, bool $forceUpdate = false): bool;
}
