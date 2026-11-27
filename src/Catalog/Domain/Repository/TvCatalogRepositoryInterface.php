<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface TvCatalogRepositoryInterface
{
    /** @param list<string> $keys */
    public function lockImportIdentities(array $keys): void;

    public function findSeriesByUuid(Uuid $id): ?TvSeries;

    public function findSeriesByPublicId(PublicId $id): ?TvSeries;

    public function findSeriesByFolder(Uuid $libraryId, string $folderPath): ?TvSeries;

    public function findSeriesByVideo(Uuid $libraryId, Uuid $videoId): ?TvSeries;

    public function findSeason(Uuid $seriesId, int $seasonNumber): ?TvSeason;

    public function findEpisodeByUuid(Uuid $id): ?TvEpisode;

    public function findEpisodeByNumber(Uuid $seriesId, int $seasonNumber, int $episodeNumber): ?TvEpisode;

    public function findEpisodeByAbsoluteNumber(Uuid $seriesId, int $absoluteNumber): ?TvEpisode;

    /** @return list<TvEpisode> */
    public function episodesByAirDate(Uuid $seriesId, string $airDate): array;

    /** @return list<TvEpisode> */
    public function episodesBySeries(Uuid $seriesId): array;

    public function findPlacement(Uuid $libraryId, Uuid $videoId): ?VideoPlacement;

    /** @return list<VideoPlacement> */
    public function placementsBySeries(Uuid $seriesId): array;

    /** @return list<VideoPlacement> */
    public function placementsByMovie(Uuid $movieId): array;

    public function saveSeries(TvSeries $series): void;

    public function saveSeason(TvSeason $season): void;

    public function saveEpisode(TvEpisode $episode): void;

    public function savePlacement(VideoPlacement $placement): void;

    public function flush(): void;
}
