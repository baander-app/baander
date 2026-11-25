<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\ValueObject\DirectorCredit;
use App\Catalog\Infrastructure\DirectorService;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MoviePersonCreditEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonExternalIdEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\DirectorRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use DoctrineMigrations\Version20261121143000;
use DoctrineMigrations\Version20261121194500;
use DoctrineMigrations\Version20261121194800;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261121143000.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261121194500.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261121194800.php';

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
        foreach (['libraries', 'movies', 'videos', 'movie_video', 'person_id_aliases'] as $table) {
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
        $this->migrate(new Version20261121143000($this->writer, new NullLogger()));
        $this->migrate(new Version20261121194500($this->writer, new NullLogger()));
        $this->migrate(new Version20261121194800($this->writer, new NullLogger()));
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
            ['column_name' => 'name', 'data_type' => 'text'],
            ['column_name' => 'profile_url', 'data_type' => 'text'],
            ['column_name' => 'image_id', 'data_type' => 'uuid'],
            ['column_name' => 'biography', 'data_type' => 'text'],
            ['column_name' => 'aliases', 'data_type' => 'jsonb'],
            ['column_name' => 'birth_date', 'data_type' => 'date'],
            ['column_name' => 'birth_precision', 'data_type' => 'smallint'],
            ['column_name' => 'death_date', 'data_type' => 'date'],
            ['column_name' => 'death_precision', 'data_type' => 'smallint'],
            ['column_name' => 'is_deceased', 'data_type' => 'boolean'],
            ['column_name' => 'birthplace', 'data_type' => 'text'],
            ['column_name' => 'sex', 'data_type' => 'text'],
            ['column_name' => 'gender', 'data_type' => 'text'],
            ['column_name' => 'profile_overrides', 'data_type' => 'jsonb'],
        ], $this->observer->fetchAllAssociative(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = 'people' ORDER BY ordinal_position",
            [$this->schema],
        ));
        self::assertSame([
            'idx_movie_person_credits_person_id',
            'idx_people_image_id',
            'movie_person_credits_pkey',
            'people_pkey',
            'uniq_movie_person_credits_movie_id_credit_key',
            'uniq_people_public_id',
        ], $this->observer->fetchFirstColumn(
            'SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename IN (?, ?) ORDER BY indexname',
            [$this->schema, 'people', 'movie_person_credits'],
        ));
        self::assertSame([
            ['conname' => 'fk_movie_person_credit_state_movie_id', 'confdeltype' => 'c'],
            ['conname' => 'fk_movie_person_credits_movie_id', 'confdeltype' => 'c'],
            ['conname' => 'fk_movie_person_credits_person_id', 'confdeltype' => 'c'],
            ['conname' => 'fk_people_image_id', 'confdeltype' => 'n'],
            ['conname' => 'fk_person_external_ids_person_id', 'confdeltype' => 'c'],
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
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_person_credits'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(DISTINCT person_id) FROM movie_person_credits'));
    }

    public function testFiftyMovieFilmographyUsesFourQueriesAndIdCursorDoesNotHydrateMovies(): void
    {
        $library = $this->manager->find(MovieEntity::class, $this->first)->getLibrary();
        $person = new PersonEntity('Batch Director');
        $this->manager->persist(new PersonExternalIdEntity($person, 'tmdb_person', '2000000091', 'tmdb'));
        $this->manager->persist($person);
        $expectedVideoIds = [];
        for ($number = 1; $number <= 50; ++$number) {
            $movie = new MovieEntity(new PublicId(), $library, sprintf('Batch Movie %02d', $number));
            $video = new VideoEntity(new PublicId(), '/media/director-batch/' . $number . '.mkv', hash('xxh3', 'director-batch-' . $number));
            $expectedVideoIds[] = $video->getPublicId()->toString();
            foreach ([$movie, $video, new MovieVideoEntity($movie, $video), MoviePersonCreditEntity::director($movie, $person)] as $entity) {
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
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM people'));
        $this->writer->transactional(function () use ($repository): void {
            $repository->syncMovieDirectors($this->first, [new DirectorCredit(2_000_000_071, 'Committed Director')]);
        });
        $this->writer->executeStatement('DELETE FROM movies WHERE id = ?', [$this->first->toString()]);
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_person_credits'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM people'));
    }

    public function testPopulatedDirectorUpgradePreservesIdentitiesAndInitializesOnlyDirectors(): void
    {
        foreach (['movie_person_credits', 'movie_person_credit_state', 'person_external_ids', 'people'] as $table) {
            $this->writer->executeStatement('DROP TABLE ' . $table);
        }
        $this->migrate(new Version20261121143000($this->writer, new NullLogger()));
        $personId = Uuid::v7()->toString();
        $publicId = new PublicId()->toString();
        $this->writer->insert('movie_directors', [
            'id' => $personId,
            'public_id' => $publicId,
            'tmdb_id' => 2_000_000_092,
            'name' => 'Legacy Director',
            'profile_url' => 'https://images.baander.app/legacy.jpg',
        ]);
        foreach ([$this->first, $this->second] as $movieId) {
            $this->writer->insert('movie_director_credits', ['movie_id' => $movieId->toString(), 'director_id' => $personId]);
        }
        $this->migrate(new Version20261121194500($this->writer, new NullLogger()));
        $this->migrate(new Version20261121194800($this->writer, new NullLogger()));

        self::assertSame([
            'id' => $personId,
            'public_id' => $publicId,
            'name' => 'Legacy Director',
            'profile_url' => 'https://images.baander.app/legacy.jpg',
        ], $this->observer->fetchAssociative('SELECT id::text, public_id, name, profile_url FROM people'));
        self::assertSame([
            'namespace' => 'tmdb_person',
            'external_id' => '2000000092',
            'person_id' => $personId,
            'source' => 'tmdb',
        ], $this->observer->fetchAssociative('SELECT namespace, external_id, person_id::text, source FROM person_external_ids'));
        self::assertSame(2, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM movie_person_credits WHERE category = 'crew' AND role = 'director' AND job = 'Director' AND credit_key = ?", ['director:' . $personId]));
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_person_credit_state WHERE directors_initialized AND NOT cast_initialized AND NOT crew_initialized'));
        self::assertSame([7, 7], $this->observer->fetchFirstColumn('SELECT uuid_extract_version(id) FROM movie_person_credits'));
        self::assertNull($this->observer->fetchOne("SELECT to_regclass('" . $this->schema . ".movie_directors')"));
        $this->manager->clear();
        $repository = new DirectorRepository($this->manager);
        $view = $repository->findVisibleByPublicId(PublicId::fromString($publicId), LibraryReadScope::unrestricted());
        self::assertNotNull($view);
        self::assertSame(2, $view->movieCount);
        self::assertSame(2_000_000_092, $view->tmdbId);
    }

    public function testTwoProviderIdsForOnePersonProduceOneDirectorCreditAndDirectoryEntry(): void
    {
        $person = new PersonEntity('Several Credits');
        $movie = $this->manager->find(MovieEntity::class, $this->first);
        foreach ([
            $person,
            new PersonExternalIdEntity($person, 'tmdb_person', '2000000093', 'tmdb'),
            new PersonExternalIdEntity($person, 'tmdb_person', '2000000094', 'tmdb'),
            new MoviePersonCreditEntity($movie, $person, 'cast', 'actor', 'Actor', 'actor-fixture'),
        ] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $repository = new DirectorRepository($this->manager);
        $this->writer->transactional(fn (): bool => $repository->syncMovieDirectors($this->first, [
            new DirectorCredit(2_000_000_093, 'Several Credits'),
            new DirectorCredit(2_000_000_094, 'Several Credits'),
        ]));

        self::assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM movie_person_credits WHERE role = 'director'"));
        $page = $repository->searchVisible(SearchOptions::create('Several Credits'), LibraryReadScope::unrestricted());
        self::assertSame(1, $page->getTotal());
        self::assertCount(1, $page->getItems());
        self::assertSame(1, $page->getItems()[0]->movieCount);
        self::assertSame(1, $repository->searchMovieIdsVisible($person->getPublicId(), SearchOptions::create(''), LibraryReadScope::unrestricted())->getTotal());
        $this->writer->transactional(fn (): bool => $repository->syncMovieDirectors($this->first, [], true));
        self::assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM movie_person_credits WHERE role = 'actor'"));
        self::assertSame(0, $repository->searchVisible(SearchOptions::create('Several Credits'), LibraryReadScope::unrestricted())->getTotal());
        self::assertFalse($this->writer->transactional(fn (): bool => $repository->syncMovieDirectors($this->first, [new DirectorCredit(2_000_000_093, 'Several Credits')])));
    }

    private function migrate(AbstractMigration $migration): void
    {
        $migration->up(new Schema());
        $this->writer->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

}
