<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Entity\TvEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeasonEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEpisodeEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;

final class TvPlaybackPlacement
{
    /** @return list<object> Entities ordered with their dependencies first. */
    public static function entities(LibraryEntity $library, VideoEntity $video, string $kind, string $relativePath): array
    {
        $series = TvSeries::create($library->getId(), 'Show', 'Show');
        $seriesEntity = new TvSeriesEntity($library, $series->getState());
        $placement = VideoPlacement::create($library->getId(), $video->getId(), $relativePath, [], seriesId: $series->getId());
        $entities = [$seriesEntity];
        if ($kind === 'episode') {
            $season = TvSeason::create($series->getId(), 1);
            $seasonEntity = new TvSeasonEntity($seriesEntity, $season->getState());
            $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Pilot');
            $episodeEntity = new TvEpisodeEntity($seriesEntity, $seasonEntity, $episode->getState());
            $placement->assignEpisodes([$episode->getId()]);
            $entities[] = $seasonEntity;
            $entities[] = $episodeEntity;
        } elseif ($kind === 'extra') {
            $placement->assignExtra(ExtraKind::Trailer);
        } elseif ($kind === 'review') {
            $placement->review('unrecognized_filename');
        } else {
            throw new \InvalidArgumentException('Unknown television placement kind.');
        }
        $placementEntity = new VideoPlacementEntity($library, $video, $placement->getState());
        $placementEntity->series = $seriesEntity;
        if (isset($episodeEntity)) {
            $placementEntity->episodes->add(new VideoPlacementEpisodeEntity($placementEntity, $episodeEntity, 0));
        }
        $entities[] = $placementEntity;

        return $entities;
    }
}
