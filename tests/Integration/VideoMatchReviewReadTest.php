<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Query\VideoMatchReviewReadQuery;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoProviderPauseRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\VideoMatchQueue;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Connection;
use DoctrineMigrations\Version20261128124500;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

require_once dirname(__DIR__, 2) . '/migrations/Version20261128124500.php';

final class VideoMatchReviewReadTest extends TestCase
{
    private VideoMatchQueue $queue;
    private VideoMatchReviewReadQuery $reads;
    private TvCatalogRepository $catalog;
    private VideoPlacement $firstFile;
    private VideoPlacement $secondFile;
    private VideoPlacement $newestFile;
    private Uuid $tvLibrary;
    private DateTimeImmutable $checkedAt;
    private string $seriesPublicId;
    private string $moviePublicId;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->queue = new VideoMatchQueue();
        foreach (['tv_seasons', 'tv_episodes', 'video_placements', 'video_placement_episode', 'query_change_inbox'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->queue->writer, $table);
        }
        $this->catalog = new TvCatalogRepository($this->queue->manager);
        $series = $this->catalog->findSeriesByUuid($this->queue->seriesId);
        self::assertNotNull($series);
        $series->match('tmdb', '100', ['tmdb' => '100'], handMatched: true);
        $series->setOrdering('dvd', ['aired', 'dvd']);
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $this->tvLibrary = $series->getLibraryId();
        $this->seriesPublicId = $series->getPublicId()->toString();
        $movie = $this->queue->manager->find(MovieEntity::class, $this->queue->movieId);
        self::assertNotNull($movie);
        $this->moviePublicId = $movie->getPublicId()->toString();
        $this->checkedAt = new DateTimeImmutable('2026-01-01 00:00:00.123456+00:00');
        $now = new DateTimeImmutable();
        $states = $this->queue->states;
        $states->requestSeries($this->queue->seriesId, 'ingest', $this->checkedAt);
        $seriesToken = Uuid::v7();
        $seriesClaim = $states->claimOldestDueRequest($now, $seriesToken, 900);
        self::assertNotNull($seriesClaim);
        $states->markClaimedForReview($seriesClaim->id, $seriesToken, 'low_score', 50, $this->checkedAt);
        $states->requestMovie($this->queue->movieId, 'ingest', $this->checkedAt);
        $movieToken = Uuid::v7();
        $movieClaim = $states->claimOldestDueRequest($now, $movieToken, 900);
        self::assertNotNull($movieClaim);
        $states->markClaimedForReview($movieClaim->id, $movieToken, 'close_runner_up', 92, $this->checkedAt);
        $this->firstFile = $this->file('first.mp4', 'no_candidates', $this->checkedAt, true);
        $this->secondFile = $this->file('second.mp4', 'duplicate_file', $this->checkedAt, false);
        $this->newestFile = $this->file('newest.mp4', 'no_candidates', $this->checkedAt->modify('+1 microsecond'), true);
        $this->reads = new VideoMatchReviewReadQuery(
            $this->queue->writer,
            $this->queue->manager,
            new CursorCodec(new JsonEncoder()),
            new VideoProviderPauseRepository($this->queue->writer),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->queue)) {
            $this->queue->close();
        }
    }

    public function testMixedReviewPagesKeepMicrosecondsAndKindAndIdTieBreakers(): void
    {
        $first = $this->reads->page(null, 2);
        self::assertTrue($first->hasNextPage);
        self::assertNotNull($first->nextCursor);
        $second = $this->reads->page($first->nextCursor, 2);
        self::assertTrue($second->hasNextPage);
        self::assertNotNull($second->nextCursor);
        $last = $this->reads->page($second->nextCursor, 2);
        self::assertFalse($last->hasNextPage);
        self::assertNull($last->nextCursor);

        $items = [...$first->items, ...$second->items, ...$last->items];
        self::assertSame([
            $this->newestFile->getPublicId()->toString(),
            $this->seriesPublicId,
            $this->moviePublicId,
            $this->secondFile->getPublicId()->toString(),
            $this->firstFile->getPublicId()->toString(),
        ], array_column($items, 'publicId'));
        self::assertSame(['file', 'series', 'movie', 'file', 'file'], array_column($items, 'kind'));
        self::assertSame('123457', $items[0]->checkedAt->format('u'));
        self::assertSame('123456', $items[1]->checkedAt->format('u'));
        self::assertSame('TV', $items[0]->libraryName);
        self::assertSame('Series/newest.mp4', $items[0]->path);
        self::assertSame(['series', 'series', 'movie', 'series', 'series'], array_column($items, 'ownerKind'));
        self::assertSame([
            $this->seriesPublicId, $this->seriesPublicId, $this->moviePublicId, $this->seriesPublicId, $this->seriesPublicId,
        ], array_column($items, 'ownerPublicId'));
    }

    public function testReviewFiltersApplyBeforePaginationAndCannotReuseAnotherListsCursor(): void
    {
        $movie = $this->queue->manager->find(MovieEntity::class, $this->queue->movieId);
        self::assertNotNull($movie);
        $video = new VideoEntity(new PublicId(), '/media/movies/Heat/clip.mp4', hash('sha256', $this->queue->schema . 'other-library'));
        $this->queue->manager->persist($video);
        $this->queue->manager->flush();
        $other = VideoPlacement::create($movie->getLibrary()->getId(), $video->getId(), 'Heat/clip.mp4', [], movieId: $movie->getId());
        $other->assignExtra(ExtraKind::Trailer, assigned: true);
        $other->review('no_candidates');
        $other->getState()->reviewCheckedAt = $this->checkedAt->modify('+1 second');
        $this->catalog->savePlacement($other);
        $this->catalog->flush();
        $filtered = $this->reads->page(null, 1, 'file', $this->tvLibrary, 'no_candidates');
        self::assertCount(1, $filtered->items);
        self::assertSame($this->newestFile->getPublicId()->toString(), $filtered->items[0]->publicId);
        $movieReview = $this->reads->page(null, 1, 'file', $movie->getLibrary()->getId(), 'no_candidates')->items[0];
        self::assertSame('movie', $movieReview->ownerKind);
        self::assertSame($this->moviePublicId, $movieReview->ownerPublicId);
        $movieTarget = $this->reads->findTarget('movie', PublicId::fromString($this->moviePublicId));
        self::assertNotNull($movieTarget);
        self::assertSame(['items' => [[
            'publicId' => $other->getPublicId()->toString(), 'path' => 'Heat/clip.mp4',
            'assigned' => true, 'episodePublicIds' => [], 'extraKind' => 'trailer',
        ]], 'total' => 1], $this->reads->placementChoices($movieTarget, 1, 100));
        self::assertNotNull($filtered->nextCursor);
        $next = $this->reads->page($filtered->nextCursor, 1, 'file', $this->tvLibrary, 'no_candidates');
        self::assertSame($this->firstFile->getPublicId()->toString(), $next->items[0]->publicId);
        self::assertFalse($next->hasNextPage);

        $this->expectException(InvalidInputException::class);
        $this->reads->page($filtered->nextCursor, 1, 'movie');
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'over maximum' => [101];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidReviewLimitsAreRefused(int $limit): void
    {
        $this->expectException(InvalidInputException::class);

        $this->reads->page(null, $limit);
    }

    public function testMalformedCursorsAreRefused(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->reads->page('invalid-cursor', 20);
    }

    public function testAnEncodedCursorWithANulDateIsRefused(): void
    {
        $issued = $this->reads->page(null, 1)->nextCursor;
        self::assertNotNull($issued);
        $codec = new CursorCodec(new JsonEncoder());
        $decoded = $codec->decode($issued);
        self::assertNotNull($decoded);
        $values = $decoded->getValues();
        $values['sort'] = "\0";
        $cursor = $codec->encode(Cursor::create(CursorDirection::Next, $values));
        $this->expectException(InvalidInputException::class);

        $this->reads->page($cursor, 1);
    }

    public function testPausedProvidersIncludeFailureKindAndOnlyLivePauses(): void
    {
        $pauses = new VideoProviderPauseRepository($this->queue->writer);
        $pauses->pause('tvdb', 'login_failed');
        $pauses->pause('tmdb', 'unavailable');
        $this->queue->writer->executeStatement("UPDATE video_provider_pauses SET retry_at = clock_timestamp() - interval '1 second' WHERE provider = 'tmdb'");

        $page = $this->reads->page(null, 20);

        self::assertCount(1, $page->pausedProviders);
        self::assertSame('tvdb', $page->pausedProviders[0]->provider);
        self::assertSame('login_failed', $page->pausedProviders[0]->failureKind);
        self::assertGreaterThan(new DateTimeImmutable(), $page->pausedProviders[0]->retryAt);
    }

    public function testLibraryPreviewAndTargetIterationKeepTheirLibraryScope(): void
    {
        $preview = $this->reads->previewLibrary($this->tvLibrary);
        self::assertSame(1, $preview->totalTitles);
        self::assertSame(1, $preview->handMatchedTitles);
        self::assertSame(2, $preview->handAssignedFiles);
        self::assertSame(
            $this->queue->writer->fetchOne('SELECT public_id FROM libraries WHERE id = ?', [$this->tvLibrary->toString()]),
            $preview->libraryPublicId,
        );
        $targets = $this->reads->targetsByLibrary($this->tvLibrary, limit: 1);
        self::assertCount(1, $targets);
        self::assertSame($this->seriesPublicId, $targets[0]->publicId);
        self::assertSame([], $this->reads->targetsByLibrary($this->tvLibrary, $targets[0], 1));
        self::assertSame('tmdb', $targets[0]->matchedProvider);
        self::assertSame('100', $targets[0]->providerId);
        self::assertSame('dvd', $targets[0]->ordering);
        self::assertTrue($targets[0]->handMatched);
    }

    public function testPublicIdentifiersResolveOnlyTheNamedKind(): void
    {
        $series = $this->reads->findTarget(VideoKind::Series->value, PublicId::fromString($this->seriesPublicId));
        self::assertNotNull($series);
        self::assertSame($this->queue->seriesId->toString(), $series->id->toString());
        self::assertNull($this->reads->findTarget(VideoKind::Movie->value, PublicId::fromString($this->seriesPublicId)));
        $placement = $this->reads->findPlacement($this->firstFile->getPublicId());
        self::assertNotNull($placement);
        self::assertSame($this->firstFile->getId()->toString(), $placement->getId()->toString());
        $season = TvSeason::create($this->queue->seriesId, 1);
        $this->catalog->saveSeason($season);
        $this->catalog->flush();
        $episode = TvEpisode::create($this->queue->seriesId, $season->getId(), 1, 'Pilot');
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        $found = $this->reads->findEpisode($episode->getPublicId());
        self::assertNotNull($found);
        self::assertTrue($episode->getId()->equals($found->getId()));
    }

    public function testEpisodeChoicesPageThroughActiveEpisodesIncludingUnownedAndSeasonless(): void
    {
        $series = $this->reads->findTarget('tv', PublicId::fromString($this->seriesPublicId));
        self::assertNotNull($series);
        $season = TvSeason::create($series->id, 1);
        $this->catalog->saveSeason($season);
        $this->catalog->flush();
        $pilot = TvEpisode::create($series->id, $season->getId(), 1, 'Unowned pilot');
        $retired = TvEpisode::create($series->id, $season->getId(), 2, 'Retired');
        $retired->retire();
        $daily = TvEpisode::create($series->id, null, null, 'Daily episode', airDate: new DateTimeImmutable('2026-01-02'));
        foreach ([$pilot, $retired, $daily] as $episode) {
            $this->catalog->saveEpisode($episode);
        }
        $neighbor = TvSeries::create($this->tvLibrary, 'Neighbor', 'Neighbor');
        $this->catalog->saveSeries($neighbor);
        $this->catalog->flush();
        $this->catalog->saveEpisode(TvEpisode::create($neighbor->getId(), null, null, 'Same library, other series', absoluteNumber: 1));
        $otherLibrary = new LibraryEntity('Other TV', 'other-tv', '/media/baander.app/other-tv', 'tv_show', 'local');
        $this->queue->manager->persist($otherLibrary);
        $this->catalog->flush();
        $otherSeries = TvSeries::create($otherLibrary->getId(), 'Other', 'Other');
        $this->catalog->saveSeries($otherSeries);
        $this->catalog->flush();
        $this->catalog->saveEpisode(TvEpisode::create($otherSeries->getId(), null, null, 'Other library', absoluteNumber: 1));
        $this->catalog->flush();

        self::assertSame(['items' => [[
            'publicId' => $pilot->getPublicId()->toString(), 'seasonNumber' => 1, 'episodeNumber' => 1,
            'title' => 'Unowned pilot', 'absoluteNumber' => null, 'airDate' => null,
        ]], 'total' => 2], $this->reads->episodeChoices($series, 1, 1));
        self::assertSame(['items' => [[
            'publicId' => $daily->getPublicId()->toString(), 'seasonNumber' => null, 'episodeNumber' => null,
            'title' => 'Daily episode', 'absoluteNumber' => null, 'airDate' => '2026-01-02',
        ]], 'total' => 2], $this->reads->episodeChoices($series, 2, 1));
        self::assertSame(['items' => [], 'total' => 2], $this->reads->episodeChoices($series, 3, 1));

        $wrongLibrary = VideoMatchTargetIdentity::fromStorage(
            $series->id, 'tv', $series->publicId, $otherLibrary->getId(), true, 'tmdb', '100', 'dvd',
        );
        self::assertSame(['items' => [], 'total' => 0], $this->reads->episodeChoices($wrongLibrary, 1, 100));
        self::assertSame(['items' => [], 'total' => 0], $this->reads->placementChoices($wrongLibrary, 1, 100));
    }

    public function testPlacementChoicesIncludeUnplacedFilesAndKeepAssignedEpisodesInLinkOrder(): void
    {
        $series = $this->reads->findTarget('tv', PublicId::fromString($this->seriesPublicId));
        self::assertNotNull($series);
        $season = TvSeason::create($series->id, 1);
        $this->catalog->saveSeason($season);
        $this->catalog->flush();
        $pilot = TvEpisode::create($series->id, $season->getId(), 1, 'Pilot');
        $second = TvEpisode::create($series->id, $season->getId(), 2, 'Second');
        $this->catalog->saveEpisode($pilot);
        $this->catalog->saveEpisode($second);
        $this->catalog->flush();
        $this->newestFile->assignEpisodes([$second->getId(), $pilot->getId()], assigned: true);
        $this->catalog->savePlacement($this->newestFile);
        $neighbor = TvSeries::create($this->tvLibrary, 'Neighbor', 'Neighbor');
        $this->catalog->saveSeries($neighbor);
        $video = new VideoEntity(new PublicId(), '/media/tv/Neighbor/clip.mp4', hash('sha256', $this->queue->schema . 'neighbor'));
        $this->queue->manager->persist($video);
        $this->catalog->flush();
        $this->catalog->savePlacement(VideoPlacement::create($this->tvLibrary, $video->getId(), 'Neighbor/clip.mp4', [], seriesId: $neighbor->getId()));
        $this->catalog->flush();

        self::assertSame(['items' => [[
            'publicId' => $this->firstFile->getPublicId()->toString(), 'path' => 'Series/first.mp4',
            'assigned' => true, 'episodePublicIds' => [], 'extraKind' => 'interview',
        ]], 'total' => 3], $this->reads->placementChoices($series, 1, 1));
        self::assertSame(['items' => [[
            'publicId' => $this->newestFile->getPublicId()->toString(), 'path' => 'Series/newest.mp4',
            'assigned' => true, 'episodePublicIds' => [$second->getPublicId()->toString(), $pilot->getPublicId()->toString()], 'extraKind' => null,
        ]], 'total' => 3], $this->reads->placementChoices($series, 2, 1));
        self::assertSame(['items' => [[
            'publicId' => $this->secondFile->getPublicId()->toString(), 'path' => 'Series/second.mp4',
            'assigned' => false, 'episodePublicIds' => [], 'extraKind' => null,
        ]], 'total' => 3], $this->reads->placementChoices($series, 3, 1));
        $movie = $this->reads->findTarget('movie', PublicId::fromString($this->moviePublicId));
        self::assertNotNull($movie);
        self::assertSame(['items' => [], 'total' => 0], $this->reads->placementChoices($movie, 1, 100));
        self::assertSame(['items' => [], 'total' => 0], $this->reads->episodeChoices($movie, 1, 100));
    }

    public function testAdminSupersessionFencesAnOlderMatchAndPreservesIngestRequests(): void
    {
        $states = $this->queue->states;
        $now = new DateTimeImmutable();
        $states->requestMovie($this->queue->movieId, 'admin', $now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($now, $token, 900);
        self::assertNotNull($claim);

        $states->recordAdminMovieMatched($this->queue->movieId, $now);

        self::assertNull($states->completeClaim($claim->id, $token, $now));
        self::assertSame('matched', $states->findByMovie($this->queue->movieId)?->status);
        $states->requestSeries($this->queue->seriesId, 'admin', $now);
        $continued = $states->cancelSeriesRematch($this->queue->seriesId, $now, matched: false);
        self::assertNotNull($continued);
        self::assertSame('requested', $continued->status);
        self::assertSame('ingest', $continued->source);
        self::assertNull($states->cancelSeriesRematch($this->queue->seriesId, $now));
        $states->requestMovie($this->queue->movieId, 'admin', $now);
        self::assertSame('matched', $states->cancelMovieRematch($this->queue->movieId, $now)?->status);
    }

    public function testMigrationRechecksOutstandingReviewReasonsWithoutUsingCreationTime(): void
    {
        $this->queue->writer->executeStatement("UPDATE video_placements SET created_at = '2020-01-01 00:00:00+00'");
        $this->queue->writer->executeStatement('ALTER TABLE video_placements DROP COLUMN review_checked_at');
        $before = new DateTimeImmutable($this->queue->writer->fetchOne('SELECT clock_timestamp()'));
        $migration = new Version20261128124500($this->queue->writer, new NullLogger());
        $migration->up(new Schema());
        $this->queue->writer->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
        $after = new DateTimeImmutable($this->queue->writer->fetchOne('SELECT clock_timestamp()'));

        foreach ($this->queue->writer->fetchFirstColumn('SELECT review_checked_at FROM video_placements') as $value) {
            $checked = new DateTimeImmutable($value);
            self::assertGreaterThanOrEqual($before, $checked);
            self::assertLessThanOrEqual($after, $checked);
        }
    }

    private function file(string $name, string $reason, DateTimeImmutable $checkedAt, bool $assigned): VideoPlacement
    {
        $video = new VideoEntity(new PublicId(), '/media/tv/Series/' . $name, hash('sha256', $this->queue->schema . $name));
        $this->queue->manager->persist($video);
        $this->queue->manager->flush();
        $placement = VideoPlacement::create($this->tvLibrary, $video->getId(), 'Series/' . $name, [], seriesId: $this->queue->seriesId);
        if ($assigned) {
            $placement->assignExtra(ExtraKind::Interview, assigned: true);
        }
        $placement->review($reason);
        $placement->getState()->reviewCheckedAt = $checkedAt;
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();

        return $placement;
    }
}
