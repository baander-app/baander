<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\Doctrine\WatchableTitleLookup;
use App\Catalog\Infrastructure\VideoAvailability;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class WatchableTitleLookupTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private TvCatalogRepository $catalog;
    private LibraryEntity $library;
    private TvSeries $series;
    private TvSeason $season;
    private Uuid $member;
    private LibraryReadScope $scope;
    private WatchableTitleLookup $lookup;
    private string $schema;
    /** @var array<string, array<string, string>> */
    private array $hashes = [];
    /** @var list<array{library: string, paths: list<string>}> */
    private array $fileReads = [];

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'watchable_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies', 'tv_series', 'tv_seasons', 'tv_episodes', 'video_placements', 'video_placement_episode'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->catalog = new TvCatalogRepository($this->manager);
        $this->library = $this->library('opened');
        $this->series = TvSeries::create($this->library->getId(), 'Orbit', 'Orbit');
        $this->catalog->saveSeries($this->series);
        $this->season = TvSeason::create($this->series->getId(), 1);
        $this->catalog->saveSeason($this->season);
        $this->catalog->flush();
        $this->member = Uuid::v7();
        $this->scope = LibraryReadScope::restricted([$this->library->getId()]);
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('forUser')->willReturnCallback(function (Uuid $member): LibraryReadScope {
            self::assertTrue($this->member->equals($member));
            return $this->scope;
        });
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('findAvailableHashesByPaths')->willReturnCallback(function (Uuid $library, array $paths): array {
            $this->fileReads[] = ['library' => $library->toString(), 'paths' => $paths];
            return array_intersect_key($this->hashes[$library->toString()] ?? [], array_fill_keys($paths, true));
        });
        $this->lookup = new WatchableTitleLookup($this->connection, $scopes, new VideoAvailability(new VideoRepository($this->manager), $files));
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->manager->clear();
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->close();
    }

    public function testProgressLoadsOnlyTheSelectedSpanAndKeepsTheParentLocked(): void
    {
        $first = $this->episode(1);
        $second = $this->episode(2);
        $video = $this->video('Orbit/Span.mkv');
        $this->placement($video, [$first, $second]);
        for ($number = 3; $number <= 12; ++$number) {
            $this->placement($this->video('Orbit/E' . $number . '.mkv'), [$this->episode($number)]);
        }
        $this->connection->beginTransaction();
        $result = $this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $first->getPublicId());
        self::assertNotNull($result);
        self::assertTrue($video->getId()->equals($result->videoId));
        self::assertSame(1000.0, $result->durationSeconds);
        self::assertSame([$first->getId()->toString(), $second->getId()->toString()], array_map(static fn ($target): string => $target->id->toString(), $result->targets));
        self::assertSame([['library' => $this->library->getId()->toString(), 'paths' => [$video->getPath()]]], $this->fileReads);
        $this->assertParentLocked('tv_series', $this->series->getId());
    }

    public function testAvailabilityCannotComeFromAnotherLibraryOrAReplacedHash(): void
    {
        $episode = $this->episode(1);
        $video = $this->video('Orbit/E01.mkv');
        $this->placement($video, [$episode]);
        $other = $this->library('other');
        $otherSeries = TvSeries::create($other->getId(), 'Other', 'Other');
        $this->catalog->saveSeries($otherSeries);
        $extra = VideoPlacement::create($other->getId(), $video->getId(), 'Trailer.mkv', [], seriesId: $otherSeries->getId());
        $extra->assignExtra(ExtraKind::Trailer);
        $this->catalog->savePlacement($extra);
        $this->catalog->flush();
        $this->hashes[$other->getId()->toString()][$other->getPath() . '/Trailer.mkv'] = $video->getHash();
        $this->hashes[$this->library->getId()->toString()][$video->getPath()] = 'replaced';
        $this->connection->beginTransaction();
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'extra', $extra->getPublicId()));
        $this->scope = LibraryReadScope::unrestricted();
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $episode->getPublicId()));
        self::assertNull($this->lookup->forMark($this->member, 'series', $this->series->getPublicId()));
        foreach ($this->fileReads as $read) {
            self::assertSame($this->library->getId()->toString(), $read['library']);
        }
        $this->hashes[$this->library->getId()->toString()][$video->getPath()] = $video->getHash();
        self::assertNotNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $episode->getPublicId()));
    }

    public function testManualEpisodeMarkIncludesUnavailableVersionsWithoutMarkingItsSpanNeighbor(): void
    {
        $first = $this->episode(1);
        $second = $this->episode(2);
        $span = $this->video('Orbit/Span.mkv');
        $old = $this->video('Orbit/OldE02.mkv', false);
        $unrelated = $this->video('Orbit/E03.mkv');
        $this->placement($span, [$first, $second]);
        $this->placement($old, [$second]);
        $this->placement($unrelated, [$this->episode(3)]);
        $this->connection->beginTransaction();
        $result = $this->lookup->forMark($this->member, 'episode', $second->getPublicId());
        self::assertNotNull($result);
        self::assertCount(1, $result->targets);
        self::assertSame('episode', $result->targets[0]->kind);
        self::assertTrue($second->getId()->equals($result->targets[0]->id));
        self::assertEqualsCanonicalizing([$span->getId(), $old->getId()], $result->videoIds);
        self::assertEqualsCanonicalizing([$span->getPath(), $old->getPath()], $this->fileReads[0]['paths']);
    }

    public function testSeasonMarksOnlyOwnedEpisodesAndClearsEveryLinkedVersion(): void
    {
        $first = $this->episode(1);
        $second = $this->episode(2);
        $missing = $this->episode(3);
        $this->placement($this->video('Orbit/E01.mkv'), [$first]);
        $this->placement($this->video('Orbit/E02.mkv'), [$second]);
        $this->placement($this->video('Orbit/E03.mkv', false), [$missing]);
        $otherSeason = TvSeason::create($this->series->getId(), 2);
        $this->catalog->saveSeason($otherSeason);
        $otherEpisode = TvEpisode::create($this->series->getId(), $otherSeason->getId(), 1, 'Second season');
        $this->catalog->saveEpisode($otherEpisode);
        $otherVideo = $this->video('Orbit/S02E01.mkv');
        $this->placement($otherVideo, [$otherEpisode]);
        $this->connection->beginTransaction();
        $result = $this->lookup->forMark($this->member, 'season', $this->season->getPublicId());
        self::assertNotNull($result);
        self::assertEqualsCanonicalizing([$first->getId(), $second->getId()], array_map(static fn ($target): Uuid => $target->id, $result->targets));
        self::assertCount(2, $result->videoIds);
        self::assertNotContains($otherVideo->getPath(), $this->fileReads[0]['paths']);
    }

    public function testScopeRefusesAHiddenTitleBeforeTakingItsParentLock(): void
    {
        $episode = $this->episode(1);
        $video = $this->video('Orbit/E01.mkv');
        $this->placement($video, [$episode]);
        $this->scope = LibraryReadScope::none();
        $this->connection->beginTransaction();
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $episode->getPublicId()));
        $other = ArtistImportIdentityRace::connect($this->schema);
        try {
            $other->executeStatement("SET lock_timeout TO '100ms'");
            self::assertSame(1, $other->executeStatement('UPDATE tv_series SET name = name WHERE id = :id', ['id' => $this->series->getId()->toString()]));
        } finally {
            $other->close();
        }
        self::assertSame([], $this->fileReads);
        $this->scope = LibraryReadScope::unrestricted();
        self::assertNotNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $episode->getPublicId()));
    }

    public function testAccessIsRevalidatedAfterTheParentLock(): void
    {
        $episode = $this->episode(1);
        $video = $this->video('Orbit/E01.mkv');
        $this->placement($video, [$episode]);
        $scopes = $this->createMock(LibraryReadScopeProviderInterface::class);
        $scopes->expects(self::exactly(2))->method('forUser')->with($this->member)
            ->willReturnOnConsecutiveCalls($this->scope, LibraryReadScope::none());
        $availability = $this->createMock(VideoAvailabilityPortInterface::class);
        $availability->expects(self::never())->method('availablePaths');
        $lookup = new WatchableTitleLookup($this->connection, $scopes, $availability);
        $this->connection->beginTransaction();
        self::assertNull($lookup->forProgress($this->member, $video->getPublicId(), 'episode', $episode->getPublicId()));
        $this->assertParentLocked('tv_series', $this->series->getId());
    }

    public function testProgressRejectsAnUnrelatedTitleRetiredEpisodeAndExtra(): void
    {
        $episode = $this->episode(1);
        $other = $this->episode(2);
        $video = $this->video('Orbit/E01.mkv');
        $this->placement($video, [$episode]);
        $extraVideo = $this->video('Orbit/Trailer.mkv');
        $extra = VideoPlacement::create($this->library->getId(), $extraVideo->getId(), 'Orbit/Trailer.mkv', [], seriesId: $this->series->getId());
        $extra->assignExtra(ExtraKind::Trailer);
        $this->catalog->savePlacement($extra);
        $this->catalog->flush();
        $this->connection->beginTransaction();
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $other->getPublicId()));
        self::assertNull($this->lookup->forProgress($this->member, $extraVideo->getPublicId(), 'episode', $episode->getPublicId()));
        $extraProgress = $this->lookup->forProgress($this->member, $extraVideo->getPublicId(), 'extra', $extra->getPublicId());
        self::assertNotNull($extraProgress);
        self::assertTrue($extraVideo->getId()->equals($extraProgress->videoId));
        self::assertSame(1000.0, $extraProgress->durationSeconds);
        self::assertSame([], $extraProgress->targets);
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'extra', $extra->getPublicId()));
        $ordinaryId = $this->connection->fetchOne('SELECT public_id FROM video_placements WHERE video_id = :video', ['video' => $video->getId()->toString()]);
        self::assertIsString($ordinaryId);
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'extra', new PublicId($ordinaryId)));
        self::assertNull($this->lookup->forMark($this->member, 'extra', $extra->getPublicId()));
        $this->scope = LibraryReadScope::none();
        self::assertNull($this->lookup->forProgress($this->member, $extraVideo->getPublicId(), 'extra', $extra->getPublicId()));
        $this->scope = LibraryReadScope::restricted([$this->library->getId()]);
        $this->hashes[$this->library->getId()->toString()][$extraVideo->getPath()] = 'replaced';
        self::assertNull($this->lookup->forProgress($this->member, $extraVideo->getPublicId(), 'extra', $extra->getPublicId()));
        $this->connection->executeStatement('UPDATE tv_episodes SET retired = TRUE WHERE id = :id', ['id' => $episode->getId()->toString()]);
        self::assertNull($this->lookup->forProgress($this->member, $video->getPublicId(), 'episode', $episode->getPublicId()));
    }

    public function testMovieProgressExcludesExtrasAndManualClearIncludesUnavailableVersions(): void
    {
        $this->library = $this->library('films', 'movie');
        $this->scope = LibraryReadScope::restricted([$this->library->getId()]);
        $movie = Movie::create($this->library->getId(), 'Film');
        new MovieRepository($this->manager)->save($movie);
        $main = $this->video('Film/Main.mkv');
        $old = $this->video('Film/Old.mkv', false);
        $extra = $this->video('Film/Trailer.mkv');
        $extraPlacement = null;
        foreach ([$main, $old, $extra] as $video) {
            $placement = VideoPlacement::create($this->library->getId(), $video->getId(), substr($video->getPath(), strlen($this->library->getPath()) + 1), [], movieId: $movie->getId());
            if ($video === $extra) {
                $placement->assignExtra(ExtraKind::Trailer);
                $extraPlacement = $placement;
            }
            $this->catalog->savePlacement($placement);
        }
        $this->catalog->flush();
        $this->connection->beginTransaction();
        $result = $this->lookup->forProgress($this->member, $main->getPublicId(), 'movie', $movie->getPublicId());
        self::assertNotNull($result);
        self::assertSame('movie', $result->targets[0]->kind);
        self::assertTrue($movie->getId()->equals($result->targets[0]->id));
        self::assertNull($this->lookup->forProgress($this->member, $extra->getPublicId(), 'movie', $movie->getPublicId()));
        self::assertNotNull($extraPlacement);
        $extraProgress = $this->lookup->forProgress($this->member, $extra->getPublicId(), 'extra', $extraPlacement->getPublicId());
        self::assertNotNull($extraProgress);
        self::assertTrue($extra->getId()->equals($extraProgress->videoId));
        self::assertSame([], $extraProgress->targets);
        $mark = $this->lookup->forMark($this->member, 'movie', $movie->getPublicId());
        self::assertNotNull($mark);
        self::assertEqualsCanonicalizing([$main->getId(), $old->getId()], $mark->videoIds);
        $this->assertParentLocked('movies', $movie->getId());
    }

    public function testLookupRequiresTheCallersTransaction(): void
    {
        $this->expectException(NoActiveTransaction::class);
        $this->lookup->forMark($this->member, 'series', $this->series->getPublicId());
    }

    private function library(string $name, string $type = 'tv_show'): LibraryEntity
    {
        $library = new LibraryEntity($name, $name, '/media/baander.app/' . $name, $type, 'local');
        $this->manager->persist($library);
        $this->manager->flush();
        return $library;
    }

    private function episode(int $number): TvEpisode
    {
        $episode = TvEpisode::create($this->series->getId(), $this->season->getId(), $number, 'Episode ' . $number);
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        return $episode;
    }

    private function video(string $path, bool $available = true): VideoEntity
    {
        $absolute = $this->library->getPath() . '/' . $path;
        $video = new VideoEntity(new PublicId(), $absolute, hash('sha256', $path));
        $video->setDuration(1000);
        $this->manager->persist($video);
        $this->manager->flush();
        if ($available) {
            $this->hashes[$this->library->getId()->toString()][$absolute] = $video->getHash();
        }
        return $video;
    }

    /** @param list<TvEpisode> $episodes */
    private function placement(VideoEntity $video, array $episodes): void
    {
        $relative = substr($video->getPath(), strlen($this->library->getPath()) + 1);
        $placement = VideoPlacement::create($this->library->getId(), $video->getId(), $relative, [], seriesId: $this->series->getId());
        $placement->assignEpisodes(array_map(static fn (TvEpisode $episode): Uuid => $episode->getId(), $episodes));
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
    }

    private function assertParentLocked(string $table, Uuid $id): void
    {
        $other = ArtistImportIdentityRace::connect($this->schema);
        try {
            $other->executeStatement("SET lock_timeout TO '100ms'");
            try {
                $other->executeStatement('UPDATE ' . $table . ' SET updated_at = updated_at WHERE id = :id', ['id' => $id->toString()]);
                self::fail('The parent lock must remain held while Activity writes.');
            } catch (DriverException $failure) {
                self::assertSame('55P03', $failure->getSQLState());
            }
        } finally {
            $other->close();
        }
    }
}
