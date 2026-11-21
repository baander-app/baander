<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface PeopleReadPortInterface
{
    public function search(SearchOptions $options, LibraryReadScope $scope, ?string $role = null): SearchResult;

    /** @return array<string, mixed>|null */
    public function profile(PublicId $id, LibraryReadScope $scope): ?array;

    public function credits(PublicId $id, SearchOptions $options, LibraryReadScope $scope, ?string $kind = null, ?string $role = null): SearchResult;

    /** Returns null when the target itself is inaccessible. */
    public function workCredits(string $kind, PublicId $id, SearchOptions $options, LibraryReadScope $scope): ?SearchResult;

    /** @return array<string, mixed>|null */
    public function artistProfile(PublicId $artist, LibraryReadScope $scope): ?array;

    public function series(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    /** @return array<string, mixed>|null */
    public function seriesDetail(PublicId $id, LibraryReadScope $scope): ?array;

    public function episodes(PublicId $series, SearchOptions $options, LibraryReadScope $scope): ?SearchResult;

    /** @return array<string, mixed>|null */
    public function episodeDetail(PublicId $id, LibraryReadScope $scope): ?array;
}
