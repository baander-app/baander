<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Application\DTO\WatchedMarkEpisodeMerge;
use App\Activity\Application\DTO\WatchedMarkVideoMove;
use App\Activity\Application\Port\WatchedMarkMovePortInterface;
use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use DoctrineMigrations\Version20261121194700;
use DoctrineMigrations\Version20261128123000;
use DoctrineMigrations\Version20261128123600;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261121194700.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261128123000.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261128123600.php';

final class VideoMatchApplyStructureTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private TvCatalogRepository $catalog;
    private LibraryEntity $library;
    private TvSeries $series;
    private WatchedMarkMovePortInterface&MockObject $marks;
    private VideoPlacementService $service;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'video_structure_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->connection->executeStatement('CREATE TABLE people (id UUID PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE images (id UUID PRIMARY KEY)');
        foreach ([
            Version20261121194700::class,
            Version20261128123000::class,
            Version20261128123600::class,
        ] as $migrationClass) {
            $migration = new $migrationClass($this->connection, new NullLogger());
            $migration->up(new Schema());
            $this->connection->transactional(static function (Connection $connection) use ($migration): void {
                foreach ($migration->getSql() as $query) {
                    $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            });
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->catalog = new TvCatalogRepository($this->manager);
        $this->library = new LibraryEntity('TV structure', 'tv-structure', '/media/baander.app/tv', 'tv_show', 'local');
        $this->manager->persist($this->library);
        $this->manager->flush();
        $this->series = TvSeries::create($this->library->getId(), 'Show', 'Show');
        $this->catalog->saveSeries($this->series);
        $this->catalog->flush();
        $this->marks = $this->createMock(WatchedMarkMovePortInterface::class);
        $transaction = new class($this->manager) implements TransactionPortInterface {
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
        $this->service = new VideoPlacementService($this->catalog, new EpisodePlacer(), $this->marks, $transaction);
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

    public function testNativeNumberSwapKeepsPublicIdentitiesAndMovesMarksOnceAfterLinksAreWritten(): void
    {
        $this->series->match('tvdb', '10', ['tvdb' => '10']);
        $this->catalog->saveSeries($this->series);
        $first = $this->episode(1, 1, 'First', '101');
        $second = $this->episode(1, 2, 'Second', '102');
        $one = $this->placement('Show/Show.S01E01.mkv', ['seasonNumber' => 1, 'episodeNumbers' => [1]], [$first->getId()]);
        $two = $this->placement('Show/Show.S01E02.mkv', ['seasonNumber' => 1, 'episodeNumbers' => [2]], [$second->getId()]);
        $operation = Uuid::v7();
        $this->marks->expects(self::once())->method('move')->willReturnCallback(function (
            Uuid $id,
            array $videos,
            array $retained,
            array $merges,
        ) use ($operation, $first, $second): void {
            self::assertTrue($operation->equals($id));
            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT episode_number FROM tv_episodes WHERE id = ?', [$first->getId()], ['uuid']));
            self::assertSame([], $videos);
            self::assertEqualsCanonicalizing([$first->getId(), $second->getId()], $retained);
            self::assertSame([], $merges);
        });

        self::assertTrue($this->apply($this->title([
            new VideoSeason(1, episodes: [
                new VideoEpisode('102', 1, 'Second'),
                new VideoEpisode('101', 2, 'First'),
            ]),
        ]), $operation));
        $this->manager->clear();

        self::assertTrue($this->catalog->findEpisodeByNumber($this->series->getId(), 1, 2)?->getPublicId()->equals($first->getPublicId()));
        self::assertTrue($this->catalog->findEpisodeByNumber($this->series->getId(), 1, 1)?->getPublicId()->equals($second->getPublicId()));
        foreach ([$one, $two] as $placement) {
            self::assertSame('name_disagreement', $this->stored($placement)->getReviewReason());
            self::assertEquals($placement->getEpisodeIds(), $this->stored($placement)->getEpisodeIds());
        }
    }

    public function testMatchPlacesProvisionalAbsoluteAndDateFilesAndRetainsUnlistedAndHandAssignedFiles(): void
    {
        $absolute = $this->episode(null, null, 'Episode 137', absolute: 137);
        $dated = $this->episode(null, null, '2011-04-17', airDate: '2011-04-17');
        $unlisted = $this->episode(5, 13, 'Episode 13');
        $assigned = $this->episode(0, 7, 'Special');
        $absoluteFile = $this->placement('Show/Show - 137.mkv', ['absoluteNumber' => 137], [$absolute->getId()]);
        $dateFile = $this->placement('Show/Show.2011-04-17.mkv', ['airDate' => '2011-04-17'], [$dated->getId()]);
        $futureFile = $this->placement('Show/Show.S05E13.mkv', ['seasonNumber' => 5, 'episodeNumbers' => [13]], [$unlisted->getId()]);
        $handFile = $this->placement('Show/finale.mkv', [], [$assigned->getId()], assigned: true);
        $this->marks->expects(self::once())->method('move');

        self::assertTrue($this->apply($this->title([
            new VideoSeason(0, episodes: [new VideoEpisode('special-7', 7, 'Special')]),
            new VideoSeason(5, episodes: [new VideoEpisode('episode-137', 12, 'Finale', absoluteNumber: 137, airDate: '2011-04-17')]),
        ])));
        $this->manager->clear();

        $placed = $this->catalog->findEpisodeByNumber($this->series->getId(), 5, 12);
        self::assertNotNull($placed);
        self::assertSame('episode-137', $placed->getProviderId());
        self::assertSame(137, $placed->getAbsoluteNumber());
        self::assertEquals([$placed->getId()], $this->stored($absoluteFile)->getEpisodeIds());
        self::assertEquals([$placed->getId()], $this->stored($dateFile)->getEpisodeIds());
        self::assertSame('no_candidates', $this->stored($futureFile)->getReviewReason());
        self::assertEquals([$unlisted->getId()], $this->stored($futureFile)->getEpisodeIds());
        self::assertTrue($this->stored($handFile)->isAssigned());
        self::assertEquals([$assigned->getId()], $this->stored($handFile)->getEpisodeIds());
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM video_placements'));
    }

    public function testProvisionalRowsForOneProviderEpisodeMergeAndAnUnlinkedRemovedEpisodeRetires(): void
    {
        $numbered = $this->episode(1, 1, 'Episode 1');
        $absolute = $this->episode(null, null, 'Episode 1', absolute: 1);
        $orphan = $this->episode(1, 2, 'Removed');
        $one = $this->placement('Show/Show.S01E01.mkv', ['seasonNumber' => 1, 'episodeNumbers' => [1]], [$numbered->getId()]);
        $two = $this->placement('Show/Show - 1.mkv', ['absoluteNumber' => 1], [$absolute->getId()]);
        $merges = [];
        $this->marks->expects(self::once())->method('move')->willReturnCallback(function (
            Uuid $operation,
            array $videos,
            array $retained,
            array $merged,
        ) use (&$merges): void {
            $merges = $merged;
        });

        self::assertTrue($this->apply($this->title([
            new VideoSeason(1, episodes: [new VideoEpisode('episode-1', 1, 'Pilot', absoluteNumber: 1)]),
        ])));
        $this->manager->clear();

        $survivor = $this->catalog->findEpisodeByNumber($this->series->getId(), 1, 1);
        self::assertNotNull($survivor);
        self::assertContains($survivor->getId()->toString(), [$numbered->getId()->toString(), $absolute->getId()->toString()]);
        self::assertEquals([$survivor->getId()], $this->stored($one)->getEpisodeIds());
        self::assertEquals([$survivor->getId()], $this->stored($two)->getEpisodeIds());
        self::assertCount(1, $merges);
        self::assertInstanceOf(WatchedMarkEpisodeMerge::class, $merges[0]);
        self::assertTrue($survivor->getId()->equals($merges[0]->targetEpisodeId));
        self::assertNotEquals($merges[0]->sourceEpisodeId, $merges[0]->targetEpisodeId);
        self::assertNull($this->catalog->findEpisodeByUuid($merges[0]->sourceEpisodeId));
        self::assertTrue($this->catalog->findEpisodeByUuid($orphan->getId())?->isRetired());
    }

    public function testMissingSelectedProviderGroupPreservesItsStoredMapping(): void
    {
        $this->series->match('tvdb', '10', ['tvdb' => '10']);
        $this->series->setOrdering('group-dvd-02', ['aired', 'group-dvd-01', 'group-dvd-02']);
        $this->catalog->saveSeries($this->series);
        $episode = $this->episode(1, 3, 'Third', 'third');
        $placement = $this->placement('Show/Show.S01E03.mkv', ['seasonNumber' => 1, 'episodeNumbers' => [3]], [$episode->getId()]);
        $this->marks->expects(self::never())->method('move');

        self::assertFalse($this->apply($this->title([
            new VideoSeason(1, episodes: [new VideoEpisode('third', 1, 'Third')]),
        ])));
        $this->manager->clear();

        self::assertSame('group-dvd-02', $this->catalog->findSeriesByUuid($this->series->getId())?->getOrdering());
        self::assertTrue($this->catalog->findEpisodeByNumber($this->series->getId(), 1, 3)?->getId()->equals($episode->getId()));
        self::assertEquals([$episode->getId()], $this->stored($placement)->getEpisodeIds());
    }

    public function testHandReplacementIncludesCompleteLinksAndOnlyExistingRetainedVersions(): void
    {
        $first = $this->episode(1, 1, 'First');
        $second = $this->episode(1, 2, 'Second');
        $moving = $this->placement('Show/clip.mkv', [], [$first->getId()]);
        $staying = $this->placement('Show/Show.S01E01.hd.mkv', [], [$first->getId()], assigned: true);
        $operation = Uuid::v7();
        $this->marks->expects(self::once())->method('move')->willReturnCallback(function (
            Uuid $id,
            array $videos,
            array $retained,
            array $merges,
        ) use ($operation, $moving, $first, $second): void {
            self::assertTrue($operation->equals($id));
            $this->assertMove($videos, $moving, [$first->getId()], [$second->getId()]);
            self::assertCount(1, $videos);
            self::assertEquals([$first->getId()], $retained);
            self::assertNotContains($second->getId(), $retained);
            self::assertSame([], $merges);
            self::assertSame(
                $second->getId()->toString(),
                $this->connection->fetchOne('SELECT episode_id FROM video_placement_episode WHERE placement_id = ?', [$moving->getId()], ['uuid']),
            );
        });

        self::assertTrue($this->service->replaceAssignment($operation, $this->series->getId(), $moving->getId(), [$second->getId()]));
        $this->manager->clear();

        self::assertTrue($this->stored($moving)->isAssigned());
        self::assertEquals([$second->getId()], $this->stored($moving)->getEpisodeIds());
    }

    public function testActivityFailureRollsBackTheStructureAndLinksTogether(): void
    {
        $episode = $this->episode(null, null, 'Episode 1', absolute: 1);
        $placement = $this->placement('Show/Show - 1.mkv', ['absoluteNumber' => 1], [$episode->getId()]);
        $failure = new \RuntimeException('Activity move failed.');
        $this->marks->expects(self::once())->method('move')->willThrowException($failure);
        $caught = null;
        try {
            $this->apply($this->title([
                new VideoSeason(1, episodes: [new VideoEpisode('episode-1', 1, 'Pilot', absoluteNumber: 1)]),
            ]));
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        $this->manager->clear();

        self::assertSame($failure, $caught);
        $stored = $this->catalog->findEpisodeByUuid($episode->getId());
        self::assertNotNull($stored);
        self::assertNull($stored->getProviderId());
        self::assertNull($stored->getSeasonId());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_seasons'));
        self::assertEquals([$episode->getId()], $this->stored($placement)->getEpisodeIds());
    }

    private function episode(
        ?int $seasonNumber,
        ?int $number,
        string $name,
        ?string $providerId = null,
        ?int $absolute = null,
        ?string $airDate = null,
    ): TvEpisode {
        $season = $seasonNumber === null ? null : $this->catalog->findSeason($this->series->getId(), $seasonNumber);
        if ($seasonNumber !== null && $season === null) {
            $season = TvSeason::create($this->series->getId(), $seasonNumber);
            $this->catalog->saveSeason($season);
            $this->catalog->flush();
        }
        $episode = TvEpisode::create(
            $this->series->getId(),
            $season?->getId(),
            $number,
            $name,
            absoluteNumber: $absolute,
            airDate: $airDate === null ? null : new DateTimeImmutable($airDate),
        );
        if ($providerId !== null) {
            $episode->match($providerId);
        }
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();

        return $episode;
    }

    /** @param array<string, mixed> $parse
     * @param list<Uuid> $episodes
     */
    private function placement(string $path, array $parse, array $episodes, bool $assigned = false): VideoPlacement
    {
        $video = new VideoEntity(new PublicId(), '/media/baander.app/' . $path, hash('sha256', $path), Uuid::v7());
        $this->manager->persist($video);
        $this->manager->flush();
        $placement = VideoPlacement::create($this->library->getId(), $video->getId(), $path, $parse, seriesId: $this->series->getId());
        $placement->assignEpisodes($episodes, assigned: $assigned);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();

        return $placement;
    }

    /** @param list<VideoSeason> $seasons */
    private function title(array $seasons): VideoTitle
    {
        return new VideoTitle(
            new VideoCandidate('10', 'Show'),
            orderings: [new VideoOrdering('aired', 'Aired', 'aired')],
            seasons: $seasons,
        );
    }

    private function apply(VideoTitle $title, ?Uuid $operation = null): bool
    {
        return $this->connection->transactional(function () use ($title, $operation): bool {
            $series = $this->catalog->lockSeriesForPlacement($this->series->getId());
            self::assertNotNull($series);

            return $this->service->apply($operation ?? Uuid::v7(), $series, VideoProviderId::Tvdb, $title);
        });
    }

    private function stored(VideoPlacement $placement): VideoPlacement
    {
        $stored = $this->catalog->findPlacement($this->library->getId(), $placement->getVideoId());
        self::assertNotNull($stored);

        return $stored;
    }

    /** @param list<WatchedMarkVideoMove> $moves
     * @param list<Uuid> $before
     * @param list<Uuid> $after
     */
    private function assertMove(array $moves, VideoPlacement $placement, array $before, array $after): void
    {
        $matches = array_values(array_filter($moves, static fn (WatchedMarkVideoMove $move): bool => $move->videoId->equals($placement->getVideoId())));
        self::assertCount(1, $matches);
        self::assertEquals($before, $matches[0]->beforeEpisodeIds);
        self::assertEquals($after, $matches[0]->afterEpisodeIds);
    }
}
