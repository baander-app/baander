<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

/**
 * @phpstan-type Version array{publicId:string, width:?int, height:?int, duration:?int, videoBitrate:?int, positionSeconds:?float, lastPlayedAt:?string}
 * @phpstan-type Extra array{publicId:string, width:?int, height:?int, duration:?int, videoBitrate:?int, positionSeconds:?float, lastPlayedAt:?string, placementPublicId:string, videoPublicId:string, kind:string}
 * @phpstan-type SeriesRow array{publicId:string, libraryPublicId:string, name:string, overview:?string, posterUrl:?string, metadataSource:?string}
 * @phpstan-type SeasonRow array{publicId:string, number:int, name:?string, ownedCount:int, totalCount:int, watchedCount:int}
 * @phpstan-type TitleViewing array{watched:bool, progress:?float, defaultVideoPublicId:?string, videos:list<Version>}
 * @phpstan-type EpisodeRow array{watched:bool, progress:?float, defaultVideoPublicId:?string, videos:list<Version>, publicId:string, name:string, overview:?string, stillUrl:?string, airDate:?string, seasonPublicId:?string, seasonNumber:?int, episodeNumber:?int, absoluteNumber:?int, seriesPublicId:string, seriesName:string, libraryPublicId:string, metadataSource:?string}
 * @phpstan-type MovieRow array{watched:bool, progress:?float, defaultVideoPublicId:?string, videos:list<Version>, movie:Movie, libraryPublicId:string, metadataSource:?string, extras:list<Extra>}
 * @phpstan-type SeriesDetail array{publicId:string, libraryPublicId:string, name:string, overview:?string, posterUrl:?string, metadataSource:?string, seasons:list<SeasonRow>, unseasonedCount:int, extras:list<Extra>}
 */
interface MemberVideoCatalogReadInterface
{
    public function series(SearchOptions $options, LibraryReadScope $scope, ?PublicId $libraryId = null): SearchResult;

    /** @return SeriesDetail|null */
    public function seriesDetail(PublicId $id, LibraryReadScope $scope, ?Uuid $memberId): ?array;

    public function episodes(
        PublicId $series,
        SearchOptions $options,
        LibraryReadScope $scope,
        ?Uuid $memberId,
        ?PublicId $seasonId = null,
        bool $unseasoned = false,
    ): ?SearchResult;

    /** @return EpisodeRow|null */
    public function episodeDetail(PublicId $id, LibraryReadScope $scope, ?Uuid $memberId): ?array;

    public function movies(SearchOptions $options, LibraryReadScope $scope, ?Uuid $memberId): SearchResult;

    /** @return MovieRow|null */
    public function movieDetail(PublicId $id, LibraryReadScope $scope, ?Uuid $memberId): ?array;
}
