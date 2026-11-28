<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Activity\Application\Port\WatchedMarkMovePortInterface;
use App\Catalog\Application\Command\RefreshVideoMatchesCommand;
use App\Catalog\Application\CommandHandler\RefreshVideoMatchesHandler;
use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Application\Service\VideoMatch\VideoMatchRefresh;
use App\Catalog\Application\Service\VideoMatch\VideoMatchStructureLookup;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Catalog\Domain\Service\VideoName\MovieNameParser;
use App\Catalog\Domain\Service\VideoName\SidecarName;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchRefresher;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchRefreshRequester;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchRefreshReview;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchTargetLoader;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Library\Application\Port\VideoSidecarIndexInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class VideoMatchRefreshTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private string $schema;
    private MovieRepository $movies;
    private TvCatalogRepository $catalog;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }

        $this->schema = 'video_refresh_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach ([
            'libraries', 'movies', 'movie_video', 'tv_series', 'tv_seasons', 'tv_episodes',
            'videos', 'video_placements', 'video_placement_episode', 'video_match_states',
        ] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->movies = new MovieRepository($this->manager);
        $this->catalog = new TvCatalogRepository($this->manager);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if (isset($this->manager)) {
                $this->manager->close();
            }
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testManualAndScheduledRequestsQueueOnlyMatchedTitlesInScope(): void
    {
        $tvLibrary = $this->library('tv_show');
        $movieLibrary = $this->library('movie');
        [$series] = $this->seriesWithAssignment($tvLibrary);
        $this->catalog->saveSeries(TvSeries::create($tvLibrary, 'Unmatched', 'Unmatched'));
        $this->catalog->flush();
        $movie = Movie::create($movieLibrary, 'Movie');
        $movie->match('tmdb', '42', []);
        $this->movies->save($movie);
        $this->movies->save(Movie::create($movieLibrary, 'Unmatched'));

        $messages = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });
        $cancellation = $this->createMock(JobCancellationCheckpointInterface::class);
        $cancellation->expects($this->exactly(3))->method('check');
        $requester = new VideoMatchRefreshRequester($this->manager, $bus, $cancellation, new NativeClock());
        self::assertSame(1, $requester->requestLibrary($tvLibrary));
        self::assertSame(2, new RefreshVideoMatchesHandler($requester)(new RefreshVideoMatchesCommand()));

        foreach ($messages as $message) {
            self::assertInstanceOf(RefreshVideoMatchMessage::class, $message);
        }
        self::assertSame([VideoKind::Series, VideoKind::Series, VideoKind::Movie], array_column($messages, 'kind'));
        self::assertEquals([$series->getId(), $series->getId(), $movie->getId()], array_column($messages, 'titleId'));
    }

    public function testMissingOrderingPreservesAssignmentsAndReviewClearsWhenItReturns(): void
    {
        [$series, $episode, $placement] = $this->seriesWithAssignment($this->library('tv_show'));
        $missing = new VideoTitle(new VideoCandidate('10', 'Show'), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $provider = $this->createMock(VideoMetadataPortInterface::class);
        $calls = 0;
        $provider->expects($this->exactly(3))->method('identify')
            ->willReturnCallback(function (VideoProviderId $id, VideoRequest $request) use ($missing, &$calls): VideoTitle {
                self::assertSame(VideoProviderId::Tvdb, $id);
                self::assertSame(['tvdb' => '10'], $request->externalIds);
                self::assertSame('dvd-group', $request->ordering);
                ++$calls;

                return match ($calls) {
                    1 => $missing,
                    2 => self::dvd(5),
                    default => self::dvd(9),
                };
            });
        $marks = $this->createMock(WatchedMarkMovePortInterface::class);
        $marks->expects($this->once())->method('move');
        $refresher = $this->refresher($provider, $marks);
        $refresher->refresh(self::message($series));
        self::assertSame('ordering_unavailable', $this->connection->fetchOne('SELECT reason FROM video_match_states'));
        self::assertSame(1, $this->episodeNumber($episode));
        self::assertEquals([$episode->getId()], $this->catalog->findPlacement($series->getLibraryId(), $placement->getVideoId())?->getEpisodeIds());

        $message = self::message($series);
        $refresher->refresh($message);
        self::assertSame('matched', $this->connection->fetchOne('SELECT status FROM video_match_states'));
        self::assertNull($this->connection->fetchOne('SELECT reason FROM video_match_states'));
        $restoredNumber = $this->episodeNumber($episode);
        $refresher->refresh($message);
        self::assertSame([5, 5], [$restoredNumber, $this->episodeNumber($episode)]);
        self::assertSame(['name' => 'Show', 'overview' => 'Refreshed overview'], $this->connection->fetchAssociative('SELECT name, overview FROM tv_series'));
        $fresh = $this->catalog->findPlacement($series->getLibraryId(), $placement->getVideoId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isAssigned());
        self::assertEquals([$episode->getId()], $fresh->getEpisodeIds());
    }

    public function testLibraryRequestAdvancesPastAFullPage(): void
    {
        $library = $this->library('movie');
        $expected = [];
        for ($index = 1; $index <= 101; ++$index) {
            $movie = Movie::create($library, 'Movie ' . $index);
            $movie->match('tmdb', (string) $index, []);
            $this->movies->persist($movie);
            $expected[] = $movie->getId()->toString();
        }
        $this->movies->flush();
        sort($expected, SORT_STRING);

        $queued = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): Envelope {
            self::assertInstanceOf(RefreshVideoMatchMessage::class, $message);
            $queued[] = $message->titleId->toString();

            return new Envelope($message);
        });
        $requester = new VideoMatchRefreshRequester($this->manager, $bus, $this->createStub(JobCancellationCheckpointInterface::class), new NativeClock());

        self::assertSame(101, $requester->requestLibrary($library));
        self::assertSame($expected, $queued);
    }

    public function testUnavailableSavedProviderDoesNotLookElsewhereOrChangeItsPlacement(): void
    {
        [$series, $episode, $placement] = $this->seriesWithAssignment($this->library('tv_show'));
        $provider = $this->createMock(VideoMetadataPortInterface::class);
        $provider->expects($this->once())->method('identify')->with(VideoProviderId::Tvdb)->willReturn(VideoProviderProblem::Unavailable);
        $provider->expects($this->never())->method('search');

        try {
            $this->refresher($provider, $this->createStub(WatchedMarkMovePortInterface::class))
                ->refresh(self::message($series));
            self::fail('An unavailable saved provider must signal the worker to retry.');
        } catch (ServiceUnavailableException) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM video_match_states'));
            self::assertSame(1, $this->episodeNumber($episode));
            self::assertEquals([$episode->getId()], $this->catalog->findPlacement($series->getLibraryId(), $placement->getVideoId())?->getEpisodeIds());
        }
    }

    public function testAnOrderingChangedDuringLookupDiscardsTheStaleAnswer(): void
    {
        [$series, $episode] = $this->seriesWithAssignment($this->library('tv_show'));
        $provider = $this->createMock(VideoMetadataPortInterface::class);
        $provider->expects($this->once())->method('identify')
            ->willReturnCallback(function () use ($series): VideoTitle {
                $this->connection->executeStatement(
                    "UPDATE tv_series SET ordering = 'aired' WHERE id = ?",
                    [$series->getId()->toString()],
                );

                return self::dvd(5);
            });
        $marks = $this->createMock(WatchedMarkMovePortInterface::class);
        $marks->expects($this->never())->method('move');

        $this->refresher($provider, $marks)->refresh(self::message($series));

        self::assertSame('aired', $this->connection->fetchOne('SELECT ordering FROM tv_series'));
        self::assertSame(1, $this->episodeNumber($episode));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM video_match_states'));
    }

    private function library(string $type): Uuid
    {
        $library = new LibraryEntity('Video', 'video-refresh-' . $type, '/media/video', $type, 'local');
        $this->manager->persist($library);
        $this->manager->flush();

        return $library->getId();
    }

    /** @return array{TvSeries, TvEpisode, VideoPlacement} */
    private function seriesWithAssignment(Uuid $library): array
    {
        $series = TvSeries::create($library, 'Show', 'Show');
        $series->match('tvdb', '10', ['tmdb' => '42'], handMatched: true);
        $series->setOrdering('dvd-group', ['dvd-group']);
        $series->lockField('name');
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $season = TvSeason::create($series->getId(), 1);
        $this->catalog->saveSeason($season);
        $this->catalog->flush();
        $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Episode');
        $episode->match('99');
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        $path = 'Show/Show S01E01.mkv';
        $video = Video::create('/media/video/' . $path, hash('sha256', $path));
        new VideoRepository($this->manager)->save($video);
        $parse = new EpisodeNameParser()->parse($path)->jsonSerialize();
        $placement = VideoPlacement::create($library, $video->getId(), $path, $parse, seriesId: $series->getId());
        $placement->assignEpisodes([$episode->getId()], assigned: true);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();

        return [$series, $episode, $placement];
    }

    private function episodeNumber(TvEpisode $episode): int
    {
        return (int) $this->connection->fetchOne('SELECT episode_number FROM tv_episodes WHERE id = ?', [$episode->getId()->toString()]);
    }

    private static function message(TvSeries $series): RefreshVideoMatchMessage
    {
        return new RefreshVideoMatchMessage(VideoKind::Series, $series->getId(), Uuid::v7(), new NativeClock()->now());
    }

    private static function dvd(int $number): VideoTitle
    {
        return new VideoTitle(
            new VideoCandidate('10', 'Provider Show'),
            overview: 'Refreshed overview',
            orderings: [new VideoOrdering('dvd-group', 'DVD', 'dvd')],
            seasons: [new VideoSeason(1, episodes: [new VideoEpisode('99', $number, 'Episode', absoluteNumber: 1)])],
        );
    }

    private function refresher(VideoMetadataPortInterface $provider, WatchedMarkMovePortInterface $marks): VideoMatchRefresh
    {
        $transaction = new readonly class($this->connection) implements TransactionPortInterface {
            public function __construct(private Connection $connection) {}

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };
        $loader = new VideoMatchTargetLoader(
            $this->manager,
            $this->movies,
            $this->catalog,
            $this->createStub(VideoSidecarIndexInterface::class),
            new SidecarName(),
            new MovieNameParser(),
            $this->createStub(VideoAvailabilityPortInterface::class),
        );

        $metadata = new ApplyVideoMatchHandler(
            $this->movies,
            $this->catalog,
            $this->createStub(PersonCreditPortInterface::class),
            $this->createStub(GenrePortInterface::class),
            $this->createStub(VideoArtworkImportInterface::class),
            $this->createStub(PersonProfileSyncRequestInterface::class),
        );

        return new VideoMatchRefresh(
            $this->movies,
            $this->catalog,
            $provider,
            $loader,
            new VideoMatchStructureLookup($provider),
            new VideoMatchRefresher(
                $this->movies,
                $this->catalog,
                new VideoPlacementService($this->catalog, new EpisodePlacer(), $marks, $transaction, $this->movies),
                new VideoMatchRefreshReview($this->connection),
                $transaction,
                new NativeClock(),
                $metadata,
            ),
            new NullLogger(),
            $metadata,
        );
    }
}
