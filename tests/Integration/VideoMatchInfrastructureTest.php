<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Catalog\Domain\Service\VideoName\MovieNameParser;
use App\Catalog\Domain\Service\VideoName\SidecarName;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoMatchStateRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchApply;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchTargetLoader;
use App\Library\Application\Port\VideoSidecarIndexInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class VideoMatchInfrastructureTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private MovieRepository $movies;
    private TvCatalogRepository $catalog;
    private VideoMatchStateRepository $states;
    private MovieNameParser $movieNames;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }

        $this->schema = 'video_match_infra_' . bin2hex(random_bytes(8));
        $this->writer = ArtistImportIdentityRace::connect('public');
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach ([
            'libraries', 'movies', 'movie_video', 'tv_series', 'videos',
            'video_placements', 'video_placement_episode', 'video_match_states',
        ] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->writer, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $this->movies = new MovieRepository($this->manager);
        $this->catalog = new TvCatalogRepository($this->manager);
        $this->states = new VideoMatchStateRepository($this->writer);
        $this->movieNames = new MovieNameParser();
        $this->observer = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            if (isset($this->manager)) {
                $this->manager->close();
            }
            if (isset($this->observer)) {
                $this->observer->close();
            }
            $this->writer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->writer->close();
        }
    }

    public function testRootMovieSidecarsHaveOneStemAndCanFindTheirOwnerFromSidecarOnlyObservations(): void
    {
        $library = $this->library('movie');
        $movie = $this->movie($library, 'Title {tmdb-1399}.mkv');
        $this->movie($library, 'Other Title.mkv');
        $loader = $this->loader(['Other Title.nfo', 'movie.nfo', 'Title {tmdb-1399}.nfo', 'poster.jpg']);

        $targets = $loader->forObservations($library, ['Title {tmdb-1399}.nfo']);
        self::assertCount(1, $targets);
        self::assertTrue($targets[0]->id->equals($movie->getId()));
        self::assertEquals($movie->getId(), $targets[0]->request->titleId);
        self::assertSame(['tmdb' => '1399'], $targets[0]->request->externalIds);
        self::assertCount(1, $targets[0]->request->sidecars);
        self::assertSame(VideoSidecarKind::MovieNfo, $targets[0]->request->sidecars[0]->kind);
        self::assertSame('/media/video/Title {tmdb-1399}.nfo', $targets[0]->request->sidecars[0]->path);
        self::assertFalse($targets[0]->matched);
        self::assertSame([], $loader->forObservations($library, ['movie.nfo']));
    }

    public function testSeriesLoadsTitleAndMatchedEpisodeSidecarsAndDeduplicatesObservedOwners(): void
    {
        $library = $this->library('tv_show');
        $series = TvSeries::create($library, 'Show {tvdb-10}', 'Show', 2020);
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $relative = 'Show {tvdb-10}/Season 01/Show S01E01.mkv';
        $video = $this->video($relative);
        $parse = new EpisodeNameParser()->parse($relative)->jsonSerialize();
        $this->catalog->savePlacement(VideoPlacement::create($library, $video->getId(), $relative, $parse, seriesId: $series->getId()));
        $this->catalog->flush();

        $seriesNfo = 'Show {tvdb-10}/tvshow.nfo';
        $episodeNfo = 'Show {tvdb-10}/Season 01/Show S01E01.nfo';
        $loader = $this->loader([$seriesNfo, $episodeNfo, 'Show {tvdb-10}/Season 01/Other.nfo']);
        $targets = $loader->forObservations($library, [$relative, $episodeNfo, $seriesNfo]);
        self::assertCount(1, $targets);
        self::assertTrue($targets[0]->id->equals($series->getId()));
        self::assertSame(['tvdb' => '10'], $targets[0]->request->externalIds);
        self::assertEquals([$parse], $targets[0]->parses);
        self::assertSame([VideoSidecarKind::SeriesNfo, VideoSidecarKind::VideoNfo], array_column($targets[0]->request->sidecars, 'kind'));
        self::assertEquals($video->getId(), $targets[0]->request->sidecars[1]->videoId);
    }

    public function testMoviePrefersTheObservedFolderAfterAFileMoves(): void
    {
        $library = $this->library('movie');
        $movie = $this->movie($library, 'Renamed/Movie.mkv');
        $current = $this->catalog->placementsByMovie($movie->getId())[0];
        $previousPath = 'Original/Missing.mkv';
        $oldVideo = $this->video($previousPath);
        $this->catalog->savePlacement(VideoPlacement::create(
            $library, $oldVideo->getId(), $previousPath, $this->movieNames->parse($previousPath)->jsonSerialize(), movieId: $movie->getId(),
        ));
        $this->catalog->flush();

        $loader = $this->loader(
            ['Renamed/movie.nfo', 'Original/movie.nfo'],
            [$current->getVideoId()->toString() => true, $oldVideo->getId()->toString() => false],
        );
        $targets = $loader->forObservations($library, ['Renamed/movie.nfo']);
        self::assertCount(1, $targets);
        self::assertSame(['/media/video/Renamed/movie.nfo'], array_column($targets[0]->request->sidecars, 'path'));
    }

    public function testStaleClaimCannotApplyButLiveClaimStoresMarkerAndCompletesAtomically(): void
    {
        $library = $this->library('movie');
        $movie = $this->movie($library, 'Title.mkv');
        $now = new DateTimeImmutable();
        $this->states->requestMovie($movie->getId(), 'ingest', $now);
        $expired = $this->claim($now);
        $this->writer->executeStatement(
            "UPDATE video_match_states SET claim_expires_at = clock_timestamp() - INTERVAL '1 second' WHERE id = :id",
            ['id' => $expired->id->toString()],
        );
        $live = $this->claim($now);
        $target = $this->loader([])->load($live);
        self::assertNotNull($target);
        $answer = new VideoTitle(new VideoCandidate('2', 'Movie', externalIds: ['imdb' => 'tt0000002']));
        $apply = $this->apply($now);

        self::assertFalse($apply->apply($expired, $target, VideoProviderId::Tmdb, $answer));
        self::assertNull($this->observer->fetchOne('SELECT matched_provider FROM movies'));
        try {
            $apply->apply($live, $target, VideoProviderId::Tmdb, new VideoTitle(new VideoCandidate('', 'Movie')));
            self::fail('An empty provider entry must roll back the claim completion.');
        } catch (InvalidArgumentException) {
            self::assertSame($live->claimId?->toString(), $this->observer->fetchOne('SELECT claim_id FROM video_match_states'));
            self::assertNull($this->observer->fetchOne('SELECT matched_provider FROM movies'));
        }
        self::assertTrue($apply->apply($live, $target, VideoProviderId::Tmdb, $answer));
        self::assertSame('tmdb', $this->observer->fetchOne('SELECT matched_provider FROM movies'));
        self::assertSame('2', $this->observer->fetchOne('SELECT provider_id FROM movies'));
        self::assertSame('tt0000002', $this->observer->fetchOne("SELECT external_ids->>'imdb' FROM movies"));
        self::assertSame('matched', $this->observer->fetchOne('SELECT status FROM video_match_states'));
        self::assertNull($this->observer->fetchOne('SELECT claim_id FROM video_match_states'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM movie_video'));
    }

    public function testLiveSeriesClaimPersistsMatchIdentityWithoutReplacingExistingRemoteIds(): void
    {
        $library = $this->library('tv_show');
        $series = TvSeries::create($library, 'Show', 'Show', 2020);
        $series->match('local', 'local-fixture', ['imdb' => 'tt0000003']);
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $now = new DateTimeImmutable();
        $this->states->requestSeries($series->getId(), 'admin', $now);
        $claim = $this->claim($now);
        $target = $this->loader([])->load($claim);
        self::assertNotNull($target);
        self::assertTrue($target->matched);
        self::assertSame(['imdb' => 'tt0000003', 'local' => 'local-fixture'], $target->request->externalIds);

        $answer = new VideoTitle(new VideoCandidate('10', 'Show', externalIds: ['tmdb' => '2']));
        self::assertTrue($this->apply($now)->apply($claim, $target, VideoProviderId::Tvdb, $answer));
        self::assertSame('tvdb', $this->observer->fetchOne('SELECT matched_provider FROM tv_series'));
        self::assertSame('10', $this->observer->fetchOne('SELECT provider_id FROM tv_series'));
        self::assertSame('tt0000003', $this->observer->fetchOne("SELECT external_ids->>'imdb' FROM tv_series"));
        self::assertSame('2', $this->observer->fetchOne("SELECT external_ids->>'tmdb' FROM tv_series"));
        self::assertSame('matched', $this->observer->fetchOne('SELECT status FROM video_match_states'));
    }

    private function library(string $type): Uuid
    {
        $library = new LibraryEntity('Video', 'video-match-infra', '/media/video', $type, 'local');
        $this->manager->persist($library);
        $this->manager->flush();

        return $library->getId();
    }

    private function movie(Uuid $library, string $relativePath): Movie
    {
        $name = $this->movieNames->parse($relativePath);
        $movie = Movie::create($library, $name->title);
        $this->movies->save($movie);
        $video = $this->video($relativePath);
        $this->catalog->savePlacement(VideoPlacement::create($library, $video->getId(), $relativePath, $name->jsonSerialize(), movieId: $movie->getId()));
        $this->movies->addVideoForImport($movie->getId(), $video->getId());
        $this->catalog->flush();

        return $movie;
    }

    private function video(string $relative): Video
    {
        $video = Video::create('/media/video/' . $relative, hash('sha256', $relative));
        new VideoRepository($this->manager)->save($video);

        return $video;
    }

    /**
     * @param list<string> $files
     * @param array<string, bool> $available
     */
    private function loader(array $files, array $available = []): VideoMatchTargetLoader
    {
        $sidecars = $this->createStub(VideoSidecarIndexInterface::class);
        $sidecars->method('forFolder')->willReturnCallback(
            static function (Uuid $library, string $folder) use ($files): array {
                $contents = array_filter($files, static fn (string $file): bool => $folder === ''
                    ? dirname($file) === '.' : str_starts_with($file, $folder . '/'));

                return array_values(array_map(static fn (string $file): array => ['path' => '/media/video/' . $file, 'relativePath' => $file], $contents));
            },
        );

        $availability = $this->createStub(VideoAvailabilityPortInterface::class);
        $availability->method('forVideos')->willReturn($available);

        return new VideoMatchTargetLoader($this->manager, $this->movies, $this->catalog, $sidecars, new SidecarName(), $this->movieNames, $availability);
    }

    private function claim(DateTimeImmutable $now): VideoMatchState
    {
        $state = $this->states->claimOldestDueRequest($now, Uuid::v7(), 60);
        self::assertNotNull($state);

        return $state;
    }

    private function apply(DateTimeImmutable $now): VideoMatchApply
    {
        $transaction = new readonly class($this->writer) implements TransactionPortInterface {
            public function __construct(private Connection $connection) {}

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };

        return new VideoMatchApply($this->manager, $transaction, $this->states, $this->movies, $this->catalog, new MockClock($now));
    }
}
