<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Activity\Application\DTO\WatchedMarkVideoMove;
use App\Activity\Application\Port\WatchedMarkMovePortInterface;
use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class VideoPlacementResetTest extends TestCase
{
    public function testRematchRechecksHandAssignedFilesFromTheirParseAndMovesMarksOnce(): void
    {
        $series = TvSeries::create(Uuid::v7(), 'Show', 'Show');
        $series->match('tvdb', '10', []);
        $season = TvSeason::create($series->getId(), 1);
        $first = TvEpisode::create($series->getId(), $season->getId(), 1, 'First');
        $first->match('101');
        $second = TvEpisode::create($series->getId(), $season->getId(), 2, 'Second');
        $second->match('102');
        $feature = VideoPlacement::create(
            $series->getLibraryId(),
            Uuid::v7(),
            'Show/Show.S01E01.mkv',
            ['seasonNumber' => 1, 'episodeNumbers' => [1]],
            seriesId: $series->getId(),
        );
        $feature->assignEpisodes([$second->getId()], assigned: true);
        $extra = VideoPlacement::create(
            $series->getLibraryId(),
            Uuid::v7(),
            'Show/Show-trailer.mkv',
            ['extraKind' => ExtraKind::Trailer->value],
            seriesId: $series->getId(),
        );
        $extra->assignEpisodes([$second->getId()], assigned: true);
        $catalog = $this->createStub(TvCatalogRepositoryInterface::class);
        $catalog->method('seasonsBySeries')->willReturn([$season]);
        $catalog->method('episodesBySeries')->willReturn([$first, $second]);
        $catalog->method('placementsBySeries')->willReturn([$feature, $extra]);
        $marks = $this->createMock(WatchedMarkMovePortInterface::class);
        $operationId = Uuid::v7();
        $marks->expects(self::once())->method('move')->with($operationId, [
            new WatchedMarkVideoMove($feature->getVideoId(), [$second->getId()], [$first->getId()]),
            new WatchedMarkVideoMove($extra->getVideoId(), [$second->getId()], []),
        ], [], []);
        $service = new VideoPlacementService($catalog, new EpisodePlacer(), $marks, $this->createStub(TransactionPortInterface::class), $this->createStub(MovieRepositoryInterface::class));
        $metadata = new VideoTitle(
            new VideoCandidate('10', 'Show'),
            orderings: [new VideoOrdering('aired', 'Aired', 'aired')],
            seasons: [new VideoSeason(1, episodes: [
                new VideoEpisode('101', 1, 'First'),
                new VideoEpisode('102', 2, 'Second'),
            ])],
        );

        self::assertTrue($service->apply($operationId, $series, VideoProviderId::Tvdb, $metadata, resetAssignments: true));
        self::assertFalse($feature->isAssigned());
        self::assertSame([$first->getId()], $feature->getEpisodeIds());
        self::assertSame('assignment_reset', $feature->getReviewReason());
        self::assertNotNull($feature->getReviewCheckedAt());
        self::assertFalse($extra->isAssigned());
        self::assertSame(ExtraKind::Trailer, $extra->getExtraKind());
        self::assertSame([], $extra->getEpisodeIds());
        self::assertSame('assignment_reset', $extra->getReviewReason());
    }
}
