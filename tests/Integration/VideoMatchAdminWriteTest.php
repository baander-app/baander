<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Application\Port\WatchedMarkMovePortInterface;
use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchApply;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Application\Service\TvLibraryIngest;
use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Query\VideoMatchReviewReadQuery;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoProviderPauseRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchAdminWriter;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Library\Application\Message\DiscoveredFile;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MovieIdentityRace;
use App\Tests\Fixtures\Catalog\VideoMatchQueue;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class VideoMatchAdminWriteTest extends TestCase
{
    private VideoMatchQueue $queue;
    private MovieRepository $movies;
    private TvCatalogRepository $catalog;
    private VideoRepository $videos;
    private VideoMatchReviewReadQuery $read;
    private VideoMatchAdminWriter $writer;
    private MockClock $clock;
    private TransactionPortInterface $transaction;
    private ApplyVideoMatchHandler $metadata;
    private VideoPlacementService $placements;
    /** @var list<array<int, mixed>> */
    private array $moves = [];

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->queue = new VideoMatchQueue();
        foreach (['tv_seasons', 'tv_episodes', 'video_placements', 'video_placement_episode', 'library_file_index'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->queue->writer, $table);
        }
        $this->movies = new MovieRepository($this->queue->manager);
        $this->catalog = new TvCatalogRepository($this->queue->manager);
        $this->videos = new VideoRepository($this->queue->manager);
        $this->clock = new MockClock('2026-11-28T12:00:00+00:00');
        $this->read = new VideoMatchReviewReadQuery(
            $this->queue->writer,
            $this->queue->manager,
            new CursorCodec(new JsonEncoder()),
            new VideoProviderPauseRepository($this->queue->writer),
        );
        $transaction = new class($this->queue->manager) implements TransactionPortInterface {
            public function __construct(private EntityManager $manager) {}
            public function run(callable $operation): mixed
            {
                return $this->manager->getConnection()->transactional(function () use ($operation): mixed {
                    $result = $operation();
                    $this->manager->flush();
                    return $result;
                });
            }
        };
        $marks = $this->createStub(WatchedMarkMovePortInterface::class);
        $marks->method('move')->willReturnCallback(function (Uuid $operation, array $moves, array $retained, array $merges = []): void {
            $this->moves[] = [$moves, $retained, $merges];
        });
        $people = $this->createStub(PersonCreditPortInterface::class);
        $people->method('syncCredits')->willReturn(false);
        $metadata = new ApplyVideoMatchHandler(
            $this->movies,
            $this->catalog,
            $people,
            $this->createStub(GenrePortInterface::class),
            $this->createStub(VideoArtworkImportInterface::class),
            $this->createStub(PersonProfileSyncRequestInterface::class),
        );
        $this->transaction = $transaction;
        $this->metadata = $metadata;
        $this->placements = new VideoPlacementService($this->catalog, new EpisodePlacer(), $marks, $transaction, $this->movies);
        $this->writer = new VideoMatchAdminWriter(
            $transaction,
            $this->movies,
            $this->catalog,
            $this->queue->states,
            $this->placements,
            $metadata,
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->queue)) {
            $this->queue->close();
        }
    }

    public function testLibraryRematchCountsAndProtectsHandMatchedTitlesUnderTheWriteLock(): void
    {
        $base = $this->catalog->findSeriesByUuid($this->queue->seriesId);
        self::assertNotNull($base);
        $ids = [];
        for ($index = 0; $index < 4; ++$index) {
            $series = $index === 0 ? $base : TvSeries::create($base->getLibraryId(), 'Show ' . $index, 'Show ' . $index);
            $series->match('tmdb', (string) (100 + $index), [], handMatched: $index < 3);
            $this->catalog->saveSeries($series);
            $this->catalog->flush();
            $episode = $this->episode($series, 1, 1);
            for ($file = 0; $file < 3; ++$file) {
                $this->placement($series, $episode, $index . '-' . $file);
            }
            $ids[] = $series->getId();
        }
        $preview = $this->read->previewLibrary($base->getLibraryId());
        self::assertSame(4, $preview->totalTitles);
        self::assertSame(3, $preview->handMatchedTitles);
        self::assertSame(12, $preview->handAssignedFiles);
        $queued = 0;
        foreach ($this->read->targetsByLibrary($base->getLibraryId()) as $identity) {
            $queued += (int) $this->writer->requestLibraryTitle($identity, force: false);
        }
        self::assertSame(1, $queued);
        foreach (array_slice($ids, 0, 3) as $id) {
            self::assertNull($this->queue->states->findBySeries($id));
        }
        $state = $this->queue->states->findBySeries($ids[3]);
        self::assertNotNull($state);
        self::assertSame('admin', $state->source);
        foreach ($this->read->targetsByLibrary($base->getLibraryId()) as $identity) {
            self::assertTrue($this->writer->requestLibraryTitle($identity, force: true));
        }
        self::assertSame(4, (int) $this->queue->writer->fetchOne('SELECT count(*) FROM video_match_states WHERE source = ?', ['admin']));
    }

    public function testExplicitRematchPinsTheProviderResetsOrderingAndReturnsHandFilesToReview(): void
    {
        $series = $this->catalog->findSeriesByUuid($this->queue->seriesId);
        self::assertNotNull($series);
        $series->match('tmdb', '100', ['tmdb' => '100']);
        $series->setOrdering('dvd', ['aired', 'dvd']);
        $this->catalog->saveSeries($series);
        $old = $this->episode($series, 1, 2);
        $placement = $this->placement($series, $old, 'manual', ['seasonNumber' => 1, 'episodeNumbers' => [1]]);
        $identity = $this->identity($series);
        $metadata = $this->provided('200');

        self::assertTrue($this->writer->rematch($identity, $this->target($series), VideoProviderId::Tvdb, $metadata));
        $this->queue->manager->clear();
        $fresh = $this->catalog->findSeriesByUuid($series->getId());
        self::assertNotNull($fresh);
        self::assertSame('tvdb', $fresh->getMatchedProvider());
        self::assertSame('200', $fresh->getProviderId());
        self::assertSame('aired', $fresh->getOrdering());
        self::assertTrue($fresh->isHandMatched());
        $file = $this->catalog->findPlacement($placement->getLibraryId(), $placement->getVideoId());
        self::assertNotNull($file);
        self::assertFalse($file->isAssigned());
        self::assertSame('assignment_reset', $file->getReviewReason());
        self::assertCount(1, $this->moves);
        $matched = $this->queue->states->findBySeries($series->getId());
        self::assertNotNull($matched);
        self::assertSame('matched', $matched->status);
        self::assertNull($matched->claimId);
    }

    public function testAForcedLibraryRematchAppliesItsClaimAndReturnsManualFilesToReview(): void
    {
        $series = $this->catalog->findSeriesByUuid($this->queue->seriesId);
        self::assertNotNull($series);
        $series->match('tmdb', '100', ['tmdb' => '100'], handMatched: true);
        $series->setOrdering('dvd', ['aired', 'dvd']);
        $this->catalog->saveSeries($series);
        $old = $this->episode($series, 1, 2);
        $file = $this->placement($series, $old, 'forced', ['seasonNumber' => 1, 'episodeNumbers' => [1]]);
        self::assertTrue($this->writer->requestLibraryTitle($this->identity($series), force: true));
        $claim = $this->queue->states->claimOldestDueRequest($this->clock->now(), Uuid::v7(), 900);
        self::assertNotNull($claim);
        $metadata = $this->provided('200');
        $prepared = $this->metadata->prepare($series->getLibraryId(), $metadata);
        $apply = new VideoMatchApply($this->transaction, $this->queue->states, $this->movies, $this->catalog, $this->clock, $this->placements, $this->metadata);

        $committed = $apply->apply($claim, $this->target($series), VideoProviderId::Tvdb, new ResolvedVideoMatch($metadata, 'aired'), $prepared);
        $this->metadata->discard($prepared, $committed);

        self::assertTrue($committed);
        $this->queue->manager->clear();
        $fresh = $this->catalog->findSeriesByUuid($series->getId());
        self::assertNotNull($fresh);
        self::assertSame('tvdb', $fresh->getMatchedProvider());
        self::assertSame('aired', $fresh->getOrdering());
        self::assertFalse($fresh->isHandMatched());
        $placement = $this->catalog->findPlacement($file->getLibraryId(), $file->getVideoId());
        self::assertNotNull($placement);
        self::assertFalse($placement->isAssigned());
        self::assertSame('assignment_reset', $placement->getReviewReason());
        self::assertSame('matched', $this->queue->states->findBySeries($series->getId())?->status);
    }

    public function testOrderingKeepsManualAssignmentsAndSupersedesAnOlderQueuedRematch(): void
    {
        $series = $this->catalog->findSeriesByUuid($this->queue->seriesId);
        self::assertNotNull($series);
        $series->match('tvdb', '200', ['tvdb' => '200'], handMatched: true);
        $this->catalog->saveSeries($series);
        $episode = $this->episode($series, 1, 1);
        $episode->match('10', ['tvdb' => '10']);
        $this->catalog->saveEpisode($episode);
        $file = $this->placement($series, $episode, 'owned');
        $this->queue->states->requestSeries($series->getId(), 'admin', $this->clock->now());
        $metadata = $this->provided('200');

        self::assertTrue($this->writer->setOrdering($this->identity($series), $this->target($series), 'dvd', VideoProviderId::Tvdb, $metadata));
        $this->queue->manager->clear();
        $fresh = $this->catalog->findPlacement($file->getLibraryId(), $file->getVideoId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isAssigned());
        self::assertEquals([$episode->getId()], $fresh->getEpisodeIds());
        self::assertSame('dvd', $this->catalog->findSeriesByUuid($series->getId())?->getOrdering());
        $state = $this->queue->states->findBySeries($series->getId());
        self::assertNotNull($state);
        self::assertSame('matched', $state->status);
    }

    public function testAnExplicitEpisodeAndExtraAssignmentSurviveOrdinaryRescans(): void
    {
        $series = $this->catalog->findSeriesByUuid($this->queue->seriesId);
        self::assertNotNull($series);
        $episode = $this->episode($series, 1, 10);
        $file = $this->placement($series, $episode, 'finale', ['seasonNumber' => null, 'episodeNumbers' => []]);
        $file->resetAssignment();
        $file->review('no_candidates');
        $this->catalog->savePlacement($file);
        $this->catalog->flush();
        $video = $this->videos->findByUuid($file->getVideoId());
        self::assertNotNull($video);
        $observation = new DiscoveredFile($video->getPath(), $file->getRelativePath(), 'mp4', 100, 1, $video->getHash());
        $ingest = new TvLibraryIngest($this->catalog, $this->videos, $this->createStub(LibraryMediaFilesInterface::class), new EpisodeNameParser());
        self::assertTrue($this->writer->assignFile($file, [$episode->getId()], null));
        $this->transaction->run(function () use ($ingest, $series, $observation, $video): void {
            $known = $ingest->prepareBatch($series->getLibraryId(), [$observation]);
            $ingest->ingestFile($series->getLibraryId(), $observation, $video, $known);
        });
        $this->queue->manager->clear();
        $fresh = $this->catalog->findPlacement($file->getLibraryId(), $file->getVideoId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isAssigned());
        self::assertEquals([$episode->getId()], $fresh->getEpisodeIds());
        self::assertNull($fresh->getReviewReason());
        self::assertTrue($this->writer->assignFile($fresh, [], ExtraKind::BehindTheScenes));
        $this->transaction->run(function () use ($ingest, $series, $observation, $video): void {
            $known = $ingest->prepareBatch($series->getLibraryId(), [$observation]);
            $ingest->ingestFile($series->getLibraryId(), $observation, $video, $known);
        });
        $this->queue->manager->clear();
        $extra = $this->catalog->findPlacement($file->getLibraryId(), $file->getVideoId());
        self::assertNotNull($extra);
        self::assertTrue($extra->isAssigned());
        self::assertSame(ExtraKind::BehindTheScenes, $extra->getExtraKind());
        self::assertSame([], $extra->getEpisodeIds());
        self::assertCount(2, $this->moves);
    }

    public function testMovieExtraAssignmentRemovesTheFeatureAndSurvivesAnotherImport(): void
    {
        $movie = $this->movies->findByUuid($this->queue->movieId);
        self::assertNotNull($movie);
        $path = '/media/movies/Heat/Interview.mp4';
        $hash = hash('xxh3', 'movie-assignment');
        $video = Video::create($path, $hash);
        $this->videos->save($video);
        $file = VideoPlacement::create($movie->getLibraryId(), $video->getId(), 'Heat/Interview.mp4', ['folderPath' => 'Heat', 'extraKind' => null], movieId: $movie->getId());
        $this->catalog->savePlacement($file);
        $this->catalog->flush();
        $this->movies->addVideoForImport($movie->getId(), $video->getId());

        self::assertTrue($this->writer->assignFile($file, [], ExtraKind::Interview));
        $observation = new DiscoveredFile($path, 'Heat/Interview.mp4', 'mp4', 100, 1, $hash);
        $this->queue->writer->transactional(function () use ($movie, $observation): void {
            MovieIdentityRace::import($this->queue->manager, $movie->getLibraryId(), [$observation]);
        });
        $this->queue->manager->clear();
        $fresh = $this->catalog->findPlacement($file->getLibraryId(), $file->getVideoId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isAssigned());
        self::assertSame(ExtraKind::Interview, $fresh->getExtraKind());
        self::assertSame(0, (int) $this->queue->writer->fetchOne('SELECT count(*) FROM movie_video WHERE movie_id = ?', [$movie->getId()->toString()]));
    }

    private function episode(TvSeries $series, int $seasonNumber, int $number): TvEpisode
    {
        $season = $this->catalog->findSeason($series->getId(), $seasonNumber) ?? TvSeason::create($series->getId(), $seasonNumber);
        $this->catalog->saveSeason($season);
        $this->catalog->flush();
        $episode = TvEpisode::create($series->getId(), $season->getId(), $number, 'Episode ' . $number);
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        return $episode;
    }

    /** @param array<string, mixed> $parse */
    private function placement(TvSeries $series, TvEpisode $episode, string $suffix, array $parse = []): VideoPlacement
    {
        $path = $series->getFolderPath() . '/Season 01/' . $suffix . '.mp4';
        $video = Video::create('/media/tv/' . $path, hash('xxh3', $path));
        $this->videos->save($video);
        $file = VideoPlacement::create($series->getLibraryId(), $video->getId(), $path, $parse + ['seasonNumber' => 1, 'episodeNumbers' => [1]], seriesId: $series->getId());
        $file->assignEpisodes([$episode->getId()], assigned: true);
        $this->catalog->savePlacement($file);
        $this->catalog->flush();
        return $file;
    }

    private function identity(TvSeries $series): VideoMatchTargetIdentity
    {
        return new VideoMatchTargetIdentity(
            $series->getId(),
            VideoKind::Series,
            $series->getPublicId()->toString(),
            $series->getLibraryId(),
            $series->isHandMatched(),
            $series->getMatchedProvider(),
            $series->getProviderId(),
            $series->getOrdering(),
        );
    }

    private function target(TvSeries $series): VideoMatchTarget
    {
        return new VideoMatchTarget(
            $series->getId(),
            new VideoRequest(
                VideoKind::Series,
                $series->getName(),
                ordering: $series->getOrdering(),
                libraryId: $series->getLibraryId(),
                titleId: $series->getId(),
            ),
            [],
            $series->getMatchedProvider() !== null,
        );
    }

    private function provided(string $id): VideoTitle
    {
        return new VideoTitle(
            new VideoCandidate($id, 'Matched show'),
            orderings: [new VideoOrdering('aired', 'Aired', 'aired'), new VideoOrdering('dvd', 'DVD', 'dvd')],
            seasons: [new VideoSeason(1, episodes: [new VideoEpisode('10', 1, 'Matched episode')])],
        );
    }
}
