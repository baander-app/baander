<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\TvTitleMetadata;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use DoctrineMigrations\Version20261121194700;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261121194700.php';

final class TvCatalogPersistenceTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
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
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, 'libraries');
        $this->connection->executeStatement('CREATE TABLE people (id UUID PRIMARY KEY)');
        $migration = new Version20261121194700($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
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

    public function testRepeatedRegistrationRetainsPublicIdsAndCreatesOnlySelectedEpisode(): void
    {
        $library = new LibraryEntity('TV', 'tv-persistence', '/media/tv', 'tv_show', 'local');
        $this->manager->persist($library);
        $this->manager->flush();
        $repository = new TvCatalogRepository($this->manager);
        $seriesData = new TvTitleMetadata(123, 'A series', 'Description', null, [], []);
        $series = $this->connection->transactional(fn() => $repository->registerSeries($library->getId(), $seriesData));
        $again = $this->connection->transactional(fn() => $repository->registerSeries($library->getId(), $seriesData));
        self::assertTrue($series->id->equals($again->id));
        self::assertTrue($series->publicId->equals($again->publicId));

        $episodeData = new TvTitleMetadata(456, 'A special', null, null, [], [], '2026-11-21');
        $episode = $this->connection->transactional(fn() => $repository->registerEpisode($series, 0, 2, $episodeData));
        $episodeAgain = $this->connection->transactional(fn() => $repository->registerEpisode($series, 0, 2, $episodeData));
        self::assertTrue($episode->publicId->equals($episodeAgain->publicId));
        self::assertSame(0, $episode->seasonNumber);
        self::assertSame(2, $episode->episodeNumber);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_series'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_seasons'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_episodes'));
        self::assertCount(1, $repository->seriesByLibraryAfter($library->getId()));
        self::assertCount(1, $repository->episodesBySeriesAfter($series->id));
        self::assertSame([], $repository->episodesBySeriesAfter($series->id, $episode->id));
        self::assertTrue($repository->findSeries($series->publicId)->id->equals($series->id));
    }

    public function testFailedRegistrationRollsBackHierarchy(): void
    {
        $library = new LibraryEntity('TV', 'tv-rollback', '/media/tv-rollback', 'tv_show', 'local');
        $this->manager->persist($library);
        $this->manager->flush();
        $repository = new TvCatalogRepository($this->manager);
        try {
            $this->connection->transactional(function () use ($repository, $library): void {
                $repository->registerSeries($library->getId(), new TvTitleMetadata(9, 'A series', null, null, [], []));
                throw new \RuntimeException('Credit failure');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('Credit failure', $error->getMessage());
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_series'));
    }

    public function testLibraryDeletionCascadesOnlyItsHierarchy(): void
    {
        $first = new LibraryEntity('First TV', 'tv-first', '/media/tv-first', 'tv_show', 'local');
        $second = new LibraryEntity('Second TV', 'tv-second', '/media/tv-second', 'tv_show', 'local');
        $this->manager->persist($first);
        $this->manager->persist($second);
        $this->manager->flush();
        $repository = new TvCatalogRepository($this->manager);
        $data = new TvTitleMetadata(99, 'Shared provider series', null, null, [], []);
        $series = $this->connection->transactional(fn() => $repository->registerSeries($first->getId(), $data));
        $other = $this->connection->transactional(fn() => $repository->registerSeries($second->getId(), $data));
        $this->connection->transactional(fn() => $repository->registerEpisode($series, 1, 1, new TvTitleMetadata(1, 'Episode', null, null, [], [])));
        $this->connection->delete('libraries', ['id' => $first->getId()->toString()]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_series'));
        self::assertSame($other->id->toString(), $this->connection->fetchOne('SELECT id FROM tv_series'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_seasons'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tv_episodes'));
    }
}
