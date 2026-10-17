<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\DriverException;
use DoctrineMigrations\Version20990101000000;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Migration commands run against databases created and removed exclusively by this test. */
final class InitialSchemaMigrationTest extends TestCase
{
    private Connection $admin;
    private Connection $connection;
    private string $database;
    private string $password;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL with permission to create test databases.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->password = (string) ($parameters['password'] ?? '');
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware()]);
        $this->admin = DriverManager::getConnection($parameters, $configuration);
        $this->database = 'initial_migration_test_' . bin2hex(random_bytes(8));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->admin->quoteIdentifier($this->database));
        $parameters['dbname'] = $this->database;
        $this->connection = DriverManager::getConnection($parameters, $configuration);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        if (isset($this->database)) {
            $this->admin->executeStatement('DROP DATABASE ' . $this->admin->quoteIdentifier($this->database) . ' WITH (FORCE)');
        }
        if (isset($this->admin)) {
            $this->admin->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function existingObjects(): iterable
    {
        yield 'empty table' => ['CREATE TABLE unrelated.saved (id integer)'];
        yield 'populated table' => ['CREATE TABLE unrelated.saved (id integer); INSERT INTO unrelated.saved VALUES (17)'];
        yield 'view' => ['CREATE VIEW unrelated.saved AS SELECT 17 AS id'];
        yield 'materialized view' => ['CREATE MATERIALIZED VIEW unrelated.saved AS SELECT 17 AS id'];
        yield 'sequence' => ['CREATE SEQUENCE unrelated.saved START 17'];
        yield 'enum' => ["CREATE TYPE unrelated.saved AS ENUM ('kept')"];
        yield 'domain' => ['CREATE DOMAIN unrelated.saved AS integer CHECK (VALUE > 0)'];
        yield 'composite' => ['CREATE TYPE unrelated.saved AS (id integer)'];
        yield 'collation' => ['CREATE COLLATION unrelated.saved FROM pg_catalog."C"'];
        yield 'text search configuration' => ['CREATE TEXT SEARCH CONFIGURATION unrelated.saved (COPY = pg_catalog.simple)'];
        yield 'routine' => ["CREATE FUNCTION unrelated.saved() RETURNS integer LANGUAGE sql AS 'SELECT 17'"];
    }

    #[DataProvider('existingObjects')]
    public function testNonInteractiveCommandRefusesExistingObjectsWithoutMutation(string $sql): void
    {
        $this->connection->executeStatement('CREATE SCHEMA unrelated');
        $this->connection->executeStatement($sql);
        $before = $this->catalog('unrelated');
        $failure = $this->refused($this->factory());

        self::assertStringContainsString($this->database, $failure);
        self::assertStringContainsString('explicit approval', $failure);
        self::assertStringContainsString('separate manual reset', $failure);
        if ($this->password !== '') {
            self::assertStringNotContainsString($this->password, $failure);
        }
        self::assertSame($before, $this->catalog('unrelated'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.users')") ?: null);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        if (str_contains($sql, 'INSERT INTO')) {
            self::assertSame([17], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM unrelated.saved')));
        }
        if (str_contains($sql, 'CREATE FUNCTION')) {
            self::assertSame(17, (int) $this->connection->fetchOne('SELECT unrelated.saved()'));
        }
    }

    public function testExtensionsAndEmptyMetadataCanInitializeAndRerunAndAcceptFutureMigrations(): void
    {
        foreach (['citext', 'ltree', 'pg_stat_statements', 'pg_trgm', 'pgcrypto', 'pgroonga', 'uuid-ossp'] as $extension) {
            $this->connection->executeStatement('CREATE EXTENSION ' . $this->connection->quoteIdentifier($extension));
        }
        $factory = $this->factory();
        $factory->getMetadataStorage()->ensureInitialized();
        self::assertCount(3, $factory->getMigrationRepository()->getMigrations());
        self::assertSame(0, $this->migrate($factory));
        self::assertSame(['DoctrineMigrations\\Version20261017000000', 'DoctrineMigrations\\Version20261017130000', 'DoctrineMigrations\\Version20261017150000'], $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version'));
        self::assertSame(82, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'"));
        $this->assertSchedules();
        $this->assertIdentitiesAllocate();
        $catalog = $this->catalog('public');
        $seeds = $this->connection->fetchAllAssociative('SELECT * FROM scheduled_jobs ORDER BY id');
        $history = $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions');
        self::assertSame(0, $this->migrate($this->factory()));
        self::assertSame($catalog, $this->catalog('public'));
        self::assertSame($seeds, $this->connection->fetchAllAssociative('SELECT * FROM scheduled_jobs ORDER BY id'));
        self::assertSame($history, $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions'));

        $failure = $this->refused($this->factory(), '0');
        self::assertStringContainsString('cannot be downgraded', $failure);
        self::assertSame($catalog, $this->catalog('public'));
        self::assertSame($seeds, $this->connection->fetchAllAssociative('SELECT * FROM scheduled_jobs ORDER BY id'));
        self::assertSame($history, $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions'));

        self::assertSame(0, $this->migrate($this->factory(true)));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        self::assertSame('future_migration_probe', $this->connection->fetchOne("SELECT to_regclass('future_migration_probe')::text"));
    }

    public function testPopulatedPlaylistUpgradePreservesMetadataMembershipsAndIdentities(): void
    {
        self::assertSame(0, $this->migrate($this->factory(), 'DoctrineMigrations\\Version20261017000000'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        $ownerId = (new Uuid())->toString();
        $collaboratorId = (new Uuid())->toString();
        $libraryId = (new Uuid())->toString();
        $albumId = (new Uuid())->toString();
        $playlistId = (new Uuid())->toString();
        foreach ([$ownerId => 'owner', $collaboratorId => 'collaborator'] as $id => $name) {
            $this->connection->executeStatement(
                "INSERT INTO users (id, public_id, name, email, password, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'hash', '2026-10-01 10:00:00+00', '2026-10-02 11:00:00+00')",
                [$id, (new PublicId())->toString(), $name, $name . '@baander.app'],
            );
        }
        $this->connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at)
             VALUES (?, ?, 'Upgrade library', 'upgrade', '/upgrade', 'music', '2026-10-01 10:00:00+00', '2026-10-02 11:00:00+00')",
            [$libraryId, (new PublicId())->toString()],
        );
        $this->connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at)
             VALUES (?, ?, ?, 'Upgrade album', 'album', '2026-10-01 10:00:00+00', '2026-10-02 11:00:00+00')",
            [$albumId, (new PublicId())->toString(), $libraryId],
        );
        $this->connection->executeStatement(
            "INSERT INTO playlists (id, public_id, user_id, name, description, is_public, is_collaborative, is_smart, smart_rules, created_at, updated_at)
             VALUES (?, ?, ?, 'Kept playlist', 'Kept description', true, true, false, '{\"limit\":17}', '2026-10-01 10:00:00+00', '2026-10-02 11:00:00+00')",
            [$playlistId, (new PublicId())->toString(), $ownerId],
        );
        foreach ([3, 9] as $position) {
            $songId = (new Uuid())->toString();
            $this->connection->executeStatement(
                "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 512, 'audio/flac', '2026-10-01 10:00:00+00', '2026-10-02 11:00:00+00')",
                [$songId, (new PublicId())->toString(), $albumId, 'Song ' . $position, '/upgrade/' . $position . '.flac'],
            );
            $this->connection->executeStatement(
                "INSERT INTO playlist_song (id, playlist_id, song_id, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, '2026-10-03 12:00:00+00', '2026-10-04 13:00:00+00')",
                [(new Uuid())->toString(), $playlistId, $songId, $position],
            );
        }
        $this->connection->executeStatement(
            "INSERT INTO playlist_collaborators (id, playlist_id, user_id, role, created_at, updated_at)
             VALUES (?, ?, ?, 'editor', '2026-10-05 14:00:00+00', '2026-10-06 15:00:00+00')",
            [(new Uuid())->toString(), $playlistId, $collaboratorId],
        );
        $snapshot = function (): array {
            $rows = [];
            foreach (['users', 'libraries', 'albums', 'songs', 'playlists', 'playlist_song', 'playlist_collaborators'] as $table) {
                $rows[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            }

            return $rows;
        };
        $before = $snapshot();

        self::assertSame(0, $this->migrate($this->factory()));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        $after = $snapshot();
        self::assertSame(0, (int) $after['playlists'][0]['persistence_version']);
        $withoutVersion = $after;
        unset($withoutVersion['playlists'][0]['persistence_version']);
        self::assertSame($before, $withoutVersion, 'The forward migration preserves complete rows, including child identities, positions and timestamps.');
        $history = $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version');
        self::assertSame(0, $this->migrate($this->factory()));
        self::assertSame($after, $snapshot());
        self::assertSame($history, $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version'));

        $this->connection->beginTransaction();
        try {
            try {
                $this->connection->executeStatement('UPDATE playlists SET persistence_version = -1 WHERE id = ?', [$playlistId]);
                self::fail('Playlist persistence versions must be non-negative.');
            } catch (DriverException $error) {
                self::assertSame('23514', $error->getSQLState());
                self::assertStringContainsString('chk_playlists_persistence_version', $error->getMessage());
            }
        } finally {
            $this->connection->rollBack();
        }
        self::assertSame($after, $snapshot());
    }

    public function testFreshDatabaseInitializesWithoutPreprovisionedExtensions(): void
    {
        self::assertSame(0, $this->migrate($this->factory()));
        $this->assertSchedules();
        self::assertSame(7, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_extension WHERE extname <> 'plpgsql'"));
    }

    public function testFixtureBootstrapRefusesAnExistingTableWithoutTouchingData(): void
    {
        $this->connection->executeStatement('CREATE SCHEMA fixture');
        $this->connection->executeStatement('SET search_path TO fixture, public');
        $this->connection->executeStatement('CREATE TABLE saved (id integer); INSERT INTO saved VALUES (17)');
        try {
            FinalSchemaBootstrap::bootstrap($this->connection);
            self::fail('Fixture bootstrap must reject a populated namespace.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('fixture', $exception->getMessage());
        }
        self::assertSame(17, (int) $this->connection->fetchOne('SELECT id FROM saved'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('fixture.users')") ?: null);
    }

    private function assertSchedules(): void
    {
        $jobs = $this->connection->fetchAllAssociative('SELECT * FROM scheduled_jobs ORDER BY id');
        self::assertCount(2, $jobs);
        $expected = [
            ['0199bf3c-8a00-7000-8000-0a07c0de9e01', '30 3 * * *', 'App\\Auth\\Application\\Command\\OAuth\\PurgeExpiredOAuthCodesCommand', [], '03:30:00'],
            ['0199bf3c-8a00-7000-8000-0a07c0de9e02', '0 4 * * *', 'App\\Recommendation\\Application\\Command\\GenerateRecommendationsCommand', ['mode' => 'incremental', 'automatic' => true], '04:00:00'],
        ];
        foreach ($jobs as $index => $job) {
            [$id, $expression, $command, $parameters, $time] = $expected[$index];
            self::assertSame($id, $job['id']);
            self::assertSame($expression, $job['expression']);
            self::assertSame($command, $job['command']);
            self::assertSame($parameters, json_decode($job['parameters'], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('active', $job['status']);
            self::assertSame('messenger', $job['job_type']);
            self::assertSame(0, (int) $job['run_count']);
            self::assertNull($job['last_run_at']);
            self::assertNull($job['evaluated_through']);
            self::assertSame($jobs[0]['created_at'], $job['created_at']);
            self::assertSame($job['created_at'], $job['updated_at']);
            self::assertSame($job['created_at'], $job['recovery_after']);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{21}$/D', $job['public_id']);
            $sample = new \DateTimeImmutable($job['created_at']);
            $next = new \DateTimeImmutable($job['next_run_at']);
            self::assertSame($time, $next->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s'));
            self::assertGreaterThan($sample, $next);
            self::assertLessThanOrEqual($sample->modify('+1 day'), $next);
        }
        self::assertNotSame($jobs[0]['public_id'], $jobs[1]['public_id']);
        self::assertNotSame($jobs[0]['revision'], $jobs[1]['revision']);
    }

    private function assertIdentitiesAllocate(): void
    {
        self::assertSame(['d', 'd'], $this->connection->fetchFirstColumn("SELECT attidentity FROM pg_attribute WHERE attrelid IN ('domain_event_outbox'::regclass, 'domain_event_outbox_delivery'::regclass) AND attname = 'id' ORDER BY attrelid"));
        foreach (range(1, 2) as $expected) {
            self::assertSame($expected, (int) $this->connection->fetchOne("INSERT INTO domain_event_outbox (event_class, event_name, payload, created_at) VALUES ('Probe', 'probe', '{}', now()) RETURNING id"));
            self::assertSame($expected, (int) $this->connection->fetchOne("INSERT INTO domain_event_outbox_delivery (channel, notification_id, payload) VALUES ('email', ?, '{}') RETURNING id", ['baander.app:' . $expected]));
        }
    }

    private function factory(bool $future = false): DependencyFactory
    {
        $configuration = [
            'migrations_paths' => ['DoctrineMigrations' => dirname(__DIR__, 2) . '/migrations'],
            'table_storage' => ['table_name' => 'doctrine_migration_versions'],
            'all_or_nothing' => true,
        ];
        if ($future) {
            require_once dirname(__DIR__) . '/Fixtures/Migrations/Version20990101000000.php';
            $configuration['migrations'] = [Version20990101000000::class];
        }

        return DependencyFactory::fromConnection(new ConfigurationArray($configuration), new ExistingConnection($this->connection));
    }

    private function migrate(DependencyFactory $factory, string $version = 'latest'): int
    {
        return (new CommandTester(new MigrateCommand($factory)))->execute(['version' => $version], ['interactive' => false]);
    }

    private function refused(DependencyFactory $factory, string $version = 'latest'): string
    {
        $tester = new CommandTester(new MigrateCommand($factory));
        try {
            $exit = $tester->execute(['version' => $version], ['interactive' => false]);
        } catch (\Throwable $exception) {
            return $exception->getMessage() . $tester->getDisplay();
        }
        self::assertNotSame(0, $exit, 'Migration must refuse this target.');

        return $tester->getDisplay();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function catalog(string $schema): array
    {
        return [
            'relations' => $this->connection->fetchAllAssociative('SELECT c.oid, c.relname, c.relkind, c.relfilenode FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? ORDER BY c.oid', [$schema]),
            'types' => $this->connection->fetchAllAssociative('SELECT t.oid, t.typname, t.typtype FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE n.nspname = ? ORDER BY t.oid', [$schema]),
            'routines' => $this->connection->fetchAllAssociative('SELECT p.oid, p.proname, p.prosrc FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = ? ORDER BY p.oid', [$schema]),
        ];
    }
}
