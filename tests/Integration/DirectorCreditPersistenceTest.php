<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\ValueObject\DirectorCredit;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieDirectorEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieDirectorCreditEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\DirectorService;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use App\Catalog\Infrastructure\Doctrine\Repository\DirectorRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use DoctrineMigrations\Version20261121143000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261121143000.php';

final class DirectorCreditPersistenceTest extends TestCase
{
    private Connection $observer;
    private Connection $writer;
    private EntityManager $manager;
    private string $schema;
    private Uuid $first;
    private Uuid $second;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'director_credit_' . bin2hex(random_bytes(8));
        $this->observer = ArtistImportIdentityRace::connect('public');
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'movies', 'videos', 'movie_video'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, $table);
        }
        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $library = new LibraryEntity('Owned', 'owned', '/media/owned-directors', 'movie', 'local');
        $first = new MovieEntity(new PublicId(), $library, 'Existing Movie');
        $second = new MovieEntity(new PublicId(), $library, 'Second Movie');
        foreach ([$library, $first, $second] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $this->first = $first->getId();
        $this->second = $second->getId();
        $migration = new Version20261121143000($this->writer, new NullLogger());
        $migration->up(new Schema());
        $this->writer->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    public function testUpgradePreservesExistingMoviesAndConcurrentImportsShareOnePerson(): void
    {
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movies'));
        self::assertSame([
            ['column_name' => 'id', 'data_type' => 'uuid'],
            ['column_name' => 'public_id', 'data_type' => 'text'],
            ['column_name' => 'tmdb_id', 'data_type' => 'integer'],
            ['column_name' => 'name', 'data_type' => 'text'],
            ['column_name' => 'profile_url', 'data_type' => 'text'],
        ], $this->observer->fetchAllAssociative(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = 'movie_directors' ORDER BY ordinal_position",
            [$this->schema],
        ));
        self::assertSame([
            'idx_movie_director_credits_director_id',
            'movie_director_credits_pkey',
            'movie_directors_pkey',
            'uniq_movie_directors_public_id',
            'uniq_movie_directors_tmdb_id',
        ], $this->observer->fetchFirstColumn(
            'SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename IN (?, ?) ORDER BY indexname',
            [$this->schema, 'movie_directors', 'movie_director_credits'],
        ));
        self::assertSame([
            ['conname' => 'fk_movie_director_credits_director_id', 'confdeltype' => 'c'],
            ['conname' => 'fk_movie_director_credits_movie_id', 'confdeltype' => 'c'],
        ], $this->observer->fetchAllAssociative(
            "SELECT conname, confdeltype FROM pg_constraint WHERE connamespace = CAST(? AS regnamespace) AND contype = 'f' ORDER BY conname",
            [$this->schema],
        ));

        $tmdbId = random_int(1_500_000_000, 1_900_000_000);
        $this->writer->beginTransaction();
        new DirectorRepository($this->manager)->syncMovieDirectors($this->first, [new DirectorCredit($tmdbId, 'Concurrent Director')]);
        $output = tmpfile();
        self::assertIsResource($output);
        $application = 'director_import_' . bin2hex(random_bytes(6));
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__) . '/Fixtures/Catalog/director-credit-import.php',
            $this->schema,
            $application,
            $this->second->toString(),
            (string) $tmdbId,
        ], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        self::assertIsResource($process);
        $child = ['process' => $process, 'output' => $output, 'application' => $application];
        try {
            GenreImportRace::awaitLockWait($this->observer, $child);
        } finally {
            $this->writer->commit();
        }
        self::assertSame(['people' => 1], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_director_credits'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(DISTINCT director_id) FROM movie_director_credits'));
    }

    public function testFiftyMovieFilmographyUsesFourQueriesAndIdCursorDoesNotHydrateMovies(): void
    {
        $library = $this->manager->find(MovieEntity::class, $this->first)->getLibrary();
        $person = new MovieDirectorEntity(new DirectorCredit(2_000_000_091, 'Batch Director'));
        $this->manager->persist($person);
        $expectedVideoIds = [];
        for ($number = 1; $number <= 50; ++$number) {
            $movie = new MovieEntity(new PublicId(), $library, sprintf('Batch Movie %02d', $number));
            $video = new VideoEntity(new PublicId(), '/media/director-batch/' . $number . '.mkv', hash('xxh3', 'director-batch-' . $number));
            $expectedVideoIds[] = $video->getPublicId()->toString();
            foreach ([$movie, $video, new MovieVideoEntity($movie, $video), new MovieDirectorCreditEntity($movie, $person)] as $entity) {
                $this->manager->persist($entity);
            }
        }
        $this->manager->flush();
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            private array $queries = [];

            public function reset(): void
            {
                $this->queries = [];
            }

            /** @return list<string> */
            public function queries(): array
            {
                return $this->queries;
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware(), new Middleware($logger)]);
        $connection = DriverManager::getConnection($this->writer->getParams(), $configuration);
        $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        try {
            $manager = ArtistImportIdentityRace::entityManager($connection);
            $movies = new MovieRepository($manager);
            $directors = new DirectorService(new DirectorRepository($manager), $movies);
            $logger->reset();
            $page = $directors->searchMoviesVisible($person->getPublicId(), SearchOptions::create('', 50), LibraryReadScope::unrestricted());
            self::assertCount(4, $logger->queries());
            self::assertSame(50, $page->getTotal());
            self::assertCount(50, $page->getItems());
            foreach ($page->getItems() as $index => $movie) {
                self::assertSame(sprintf('Batch Movie %02d', $index + 1), $movie->getTitle());
                self::assertSame([$expectedVideoIds[$index]], $movie->getVideoPublicIds());
            }
            $logger->reset();
            $firstPage = $movies->movieIdsByLibraryAfter($library->getId(), null, 2);
            self::assertCount(2, $firstPage);
            $queries = $logger->queries();
            self::assertCount(1, $queries);
            self::assertArrayHasKey(0, $queries);
            self::assertStringNotContainsString('videos', $queries[0]);
            self::assertStringNotContainsString('title', $queries[0]);
            $logger->reset();
            $secondPage = $movies->movieIdsByLibraryAfter($library->getId(), $firstPage[1], 2);
            self::assertCount(2, $secondPage);
            self::assertCount(1, $logger->queries());
            self::assertGreaterThan($firstPage[1]->toString(), $secondPage[0]->toString());
        } finally {
            $connection->close();
        }
    }

    public function testMetadataAndCreditsRollbackTogetherAndCascadesRemoveOnlyCredits(): void
    {
        $repository = new DirectorRepository($this->manager);
        try {
            $this->writer->transactional(function () use ($repository): void {
                $movie = $this->manager->find(MovieEntity::class, $this->first);
                $movie->setOverview('Uncommitted metadata');
                $repository->syncMovieDirectors($this->first, [new DirectorCredit(2_000_000_071, 'Rolled Back')]);
                throw new \RuntimeException('Abort the producer transaction.');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('Abort the producer transaction.', $error->getMessage());
            $this->manager->clear();
        }
        self::assertNull($this->observer->fetchOne('SELECT overview FROM movies WHERE id = ?', [$this->first->toString()]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_directors'));
        $this->writer->transactional(function () use ($repository): void {
            $repository->syncMovieDirectors($this->first, [new DirectorCredit(2_000_000_071, 'Committed Director')]);
        });
        $this->writer->executeStatement('DELETE FROM movies WHERE id = ?', [$this->first->toString()]);
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_director_credits'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_directors'));
    }
}
