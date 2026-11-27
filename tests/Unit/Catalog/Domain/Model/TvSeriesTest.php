<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TvSeriesTest extends TestCase
{
    public function testARenamedSeriesKeepsItsMatchOrderingAndPublicIdentity(): void
    {
        $series = TvSeries::create(Uuid::v7(), 'Show', 'Show');
        $publicId = $series->getPublicId();
        $series->match('tvdb', '121361', ['tmdb' => '1399'], true);
        $series->setOrdering('absolute');
        $series->relocate('Show (2011)');

        self::assertTrue($publicId->equals($series->getPublicId()));
        self::assertSame('Show (2011)', $series->getFolderPath());
        self::assertSame('tvdb', $series->getMatchedProvider());
        self::assertTrue($series->isHandMatched());
        self::assertSame('absolute', $series->getOrdering());
    }

    public function testProvisionalEpisodeCanBePlacedWithoutChangingPublicIdentity(): void
    {
        $series = TvSeries::create(Uuid::v7(), 'Anime', 'Anime');
        $episode = TvEpisode::create($series->getId(), null, null, 'Episode 137', absoluteNumber: 137);
        $id = $episode->getPublicId();
        $season = TvSeason::create($series->getId(), 5);

        $episode->place($season->getId(), 12);
        $episode->match('episode-137');

        self::assertTrue($id->equals($episode->getPublicId()));
        self::assertSame(12, $episode->getEpisodeNumber());
        self::assertSame(137, $episode->getAbsoluteNumber());
        self::assertSame('episode-137', $episode->getProviderId());
    }

    public function testAPlacementKeepsItsAssignmentWhenItsPathAndParseChange(): void
    {
        $series = Uuid::v7();
        $episode = Uuid::v7();
        $placement = VideoPlacement::create(Uuid::v7(), Uuid::v7(), 'Show/clip.mp4', [], seriesId: $series);
        $placement->assignEpisodes([$episode], true);
        $placement->observe('Show (2011)/renamed.mp4', ['episodeNumbers' => [7]]);

        self::assertTrue($placement->isAssigned());
        self::assertTrue($placement->getEpisodeIds()[0]->equals($episode));
        self::assertSame('Show (2011)/renamed.mp4', $placement->getRelativePath());
        self::assertSame(['episodeNumbers' => [7]], $placement->getRawParse());

        $placement->assignExtra(ExtraKind::Trailer, true);
        self::assertSame([], $placement->getEpisodeIds());
        self::assertSame(ExtraKind::Trailer, $placement->getExtraKind());
    }

    public function testANegativeSeasonNumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TvSeason::create(Uuid::v7(), -1);
    }
}
