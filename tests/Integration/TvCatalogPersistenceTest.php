<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use DoctrineMigrations\Version20261121194700;
use DoctrineMigrations\Version20261128123600;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/migrations/Version20261121194700.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261128123600.php';

final class TvCatalogPersistenceTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private TvCatalogRepository $catalog;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'tv_catalog_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->connection->executeStatement('CREATE TABLE people (id UUID PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE images (id UUID PRIMARY KEY)');
        foreach ([Version20261121194700::class, Version20261128123600::class] as $migrationClass) {
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
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->manager->close();
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testASeasonHierarchyAndTwoVersionsReloadWithStablePublicIds(): void
    {
        $library = $this->library('tv-hierarchy');
        $series = TvSeries::create($library->getId(), 'Show (2011)', 'Show', 2011);
        $series->match('tvdb', '121361', ['tmdb' => '1399', 'tvdb' => '121361']);
        $this->catalog->saveSeries($series);
        $first = TvSeason::create($series->getId(), 1);
        $specials = TvSeason::create($series->getId(), 0);
        $this->catalog->saveSeason($first);
        $this->catalog->saveSeason($specials);
        $episode = TvEpisode::create($series->getId(), $first->getId(), 2, 'Episode 2');
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        $one = $this->placement($library, $series, 'Show (2011)/Season 01/Show.S01E02.mp4');
        $two = $this->placement($library, $series, 'Show (2011)/Season 01/Show.S01E02.hd.mp4');
        foreach ([$one, $two] as $placement) {
            $placement->assignEpisodes([$episode->getId()]);
            $this->catalog->savePlacement($placement);
        }
        $this->catalog->flush();
        $this->manager->clear();

        $stored = $this->catalog->findSeriesByFolder($library->getId(), 'Show (2011)');
        self::assertNotNull($stored);
        self::assertTrue($stored->getPublicId()->equals($series->getPublicId()));
        self::assertSame(['tmdb' => '1399', 'tvdb' => '121361'], $stored->getExternalIds());
        self::assertTrue($this->catalog->findSeason($series->getId(), 0)?->getPublicId()->equals($specials->getPublicId()));
        $reloaded = $this->catalog->findEpisodeByNumber($series->getId(), 1, 2);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->getPublicId()->equals($episode->getPublicId()));
        $placements = $this->catalog->placementsBySeries($series->getId());
        self::assertCount(2, $placements);
        self::assertTrue($placements[0]->getEpisodeIds()[0]->equals($episode->getId()));
        self::assertTrue($this->catalog->findSeriesByVideo($library->getId(), $one->getVideoId())?->getId()->equals($series->getId()));
    }

    public function testAPlacementCanPlayTwoEpisodesAndBeReassignedWithoutChangingItsIdentity(): void
    {
        $library = $this->library('tv-span');
        $series = TvSeries::create($library->getId(), 'Show', 'Show');
        $this->catalog->saveSeries($series);
        $season = TvSeason::create($series->getId(), 2);
        $this->catalog->saveSeason($season);
        $first = TvEpisode::create($series->getId(), $season->getId(), 1, 'Episode 1');
        $second = TvEpisode::create($series->getId(), $season->getId(), 2, 'Episode 2');
        $this->catalog->saveEpisode($first);
        $this->catalog->saveEpisode($second);
        $this->catalog->flush();
        $placement = $this->placement($library, $series, 'Show/Show.S02E01-E02.mp4');
        $placement->assignEpisodes([$first->getId(), $second->getId()], true);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
        $this->manager->clear();

        $stored = $this->catalog->findPlacement($library->getId(), $placement->getVideoId());
        self::assertNotNull($stored);
        self::assertCount(2, $stored->getEpisodeIds());
        self::assertTrue($stored->isAssigned());
        $stored->assignEpisodes([$second->getId()], true);
        $this->catalog->savePlacement($stored);
        $this->catalog->flush();
        $this->manager->clear();

        $again = $this->catalog->findPlacement($library->getId(), $placement->getVideoId());
        self::assertNotNull($again);
        self::assertTrue($again->getPublicId()->equals($placement->getPublicId()));
        self::assertCount(1, $again->getEpisodeIds());
        self::assertTrue($again->getEpisodeIds()[0]->equals($second->getId()));
    }

    public function testNumberSwapsKeepEpisodeIdentityAndPlacements(): void
    {
        $library = $this->library('tv-swap');
        $series = TvSeries::create($library->getId(), 'Show', 'Show');
        $this->catalog->saveSeries($series);
        $season = TvSeason::create($series->getId(), 1);
        $this->catalog->saveSeason($season);
        $first = TvEpisode::create($series->getId(), $season->getId(), 1, 'First');
        $second = TvEpisode::create($series->getId(), $season->getId(), 2, 'Second');
        $this->catalog->saveEpisode($first);
        $this->catalog->saveEpisode($second);
        $this->catalog->flush();

        $this->connection->transactional(function () use ($first, $second, $season): void {
            foreach ([$first, $second] as $episode) {
                $episode->place(null, null);
                $this->catalog->saveEpisode($episode);
            }
            $this->catalog->flush();
            $first->place($season->getId(), 2);
            $second->place($season->getId(), 1);
            $this->catalog->saveEpisode($first);
            $this->catalog->saveEpisode($second);
            $this->catalog->flush();
        });
        $this->manager->clear();

        self::assertTrue($this->catalog->findEpisodeByNumber($series->getId(), 1, 2)?->getPublicId()->equals($first->getPublicId()));
        self::assertTrue($this->catalog->findEpisodeByNumber($series->getId(), 1, 1)?->getPublicId()->equals($second->getPublicId()));
    }

    public function testProvisionalAbsoluteEpisodesAndExtrasPersistBeforeAMatch(): void
    {
        $library = $this->library('tv-provisional');
        $series = TvSeries::create($library->getId(), 'Anime', 'Anime');
        $this->catalog->saveSeries($series);
        $episode = TvEpisode::create($series->getId(), null, null, 'Episode 137', absoluteNumber: 137);
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        $extra = $this->placement($library, $series, 'Anime/Extras/Interview.mp4');
        $extra->assignExtra(ExtraKind::Interview);
        $this->catalog->savePlacement($extra);
        $this->catalog->flush();
        $this->manager->clear();

        $stored = $this->catalog->findEpisodeByAbsoluteNumber($series->getId(), 137);
        self::assertNotNull($stored);
        self::assertNull($stored->getSeasonId());
        self::assertNull($stored->getEpisodeNumber());
        $storedExtra = $this->catalog->findPlacement($library->getId(), $extra->getVideoId());
        self::assertNotNull($storedExtra);
        self::assertSame(ExtraKind::Interview, $storedExtra->getExtraKind());
        self::assertSame([], $storedExtra->getEpisodeIds());
    }

    public function testATitleWriteRollsBackWithItsTransaction(): void
    {
        $library = $this->library('tv-rollback');
        $failure = new RuntimeException('Credit failure');
        $caught = null;
        try {
            $this->connection->transactional(function () use ($library, $failure): never {
                $this->catalog->saveSeries(TvSeries::create($library->getId(), 'Show', 'Show'));
                $this->catalog->flush();
                throw $failure;
            });
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_series'));
    }

    public function testPlacementsProtectVideosAndLibraryDeletionKeepsOtherLibraries(): void
    {
        $first = $this->library('tv-first');
        $second = $this->library('tv-second');
        $series = TvSeries::create($first->getId(), 'Show', 'Show');
        $other = TvSeries::create($second->getId(), 'Show', 'Show');
        $this->catalog->saveSeries($series);
        $this->catalog->saveSeries($other);
        $this->catalog->flush();
        $placement = $this->placement($first, $series, 'Show/Extras/Interview.mp4');
        $placement->assignExtra(ExtraKind::Interview);
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();

        try {
            $this->connection->delete('videos', ['id' => $placement->getVideoId()->toString()]);
            self::fail('A linked video must refuse deletion.');
        } catch (ForeignKeyConstraintViolationException) {
        }

        $this->connection->delete('libraries', ['id' => $first->getId()->toString()]);
        self::assertSame([$other->getId()->toString()], $this->connection->fetchFirstColumn('SELECT id FROM tv_series'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM video_placements'));
    }

    private function library(string $slug): LibraryEntity
    {
        $library = new LibraryEntity('TV', $slug, '/media/' . $slug, 'tv_show', 'local');
        $this->manager->persist($library);
        $this->manager->flush();

        return $library;
    }

    private function placement(LibraryEntity $library, TvSeries $series, string $path): VideoPlacement
    {
        $video = new VideoEntity(new PublicId(), '/media/' . $path, hash('sha256', $path), Uuid::v7());
        $this->manager->persist($video);
        $this->manager->flush();

        return VideoPlacement::create(
            $library->getId(),
            $video->getId(),
            $path,
            ['seasonNumber' => 1, 'episodeNumbers' => [2]],
            seriesId: $series->getId(),
        );
    }
}
