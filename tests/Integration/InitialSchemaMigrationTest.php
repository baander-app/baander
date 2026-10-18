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
        self::assertCount(6, $factory->getMigrationRepository()->getMigrations());
        self::assertSame(0, $this->migrate($factory));
        self::assertSame(['DoctrineMigrations\\Version20261017000000', 'DoctrineMigrations\\Version20261017130000', 'DoctrineMigrations\\Version20261017150000', 'DoctrineMigrations\\Version20261017160000', 'DoctrineMigrations\\Version20261017170000', 'DoctrineMigrations\\Version20261018130000'], $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version'));
        self::assertSame(84, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'"));
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
        self::assertSame(7, (int) $this->connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        self::assertSame('future_migration_probe', $this->connection->fetchOne("SELECT to_regclass('future_migration_probe')::text"));
    }

    public function testMediaUpgradeKeepsKnownReceiptsAndDerivesOnlyEstablishedJobOwners(): void
    {
        self::assertSame(0, $this->migrate($this->factory(), 'DoctrineMigrations\\Version20261017150000'));
        $users = [Uuid::generate()->toString(), Uuid::generate()->toString()];
        foreach ($users as $index => $user) {
            $this->connection->executeStatement("INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, ?, 'Media upgrade', ?, 'hash', clock_timestamp(), clock_timestamp())", [$user, (new PublicId())->toString(), 'media-upgrade-' . $index . '@baander.app']);
        }
        $jobs = [];
        $videos = [];
        foreach (range(0, 3) as $index) {
            $video = Uuid::generate()->toString();
            $job = Uuid::generate()->toString();
            $videos[] = $video;
            $jobs[] = $job;
            $this->connection->executeStatement("INSERT INTO videos (id, public_id, path, hash, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())", [$video, (new PublicId())->toString(), '/media-upgrade/' . $index . '.mp4', $video]);
            $this->connection->executeStatement("INSERT INTO transcode_jobs (id, public_id, video_id, quality_tier_name, created_at, updated_at) VALUES (?, ?, ?, '720p', clock_timestamp(), clock_timestamp())", [$job, (new PublicId())->toString(), $video]);
        }
        $runtimeSession = null;
        foreach ([[$users[0], $users[1]], [$users[0], $users[0]], [$users[0], $users[1]]] as $index => $owners) {
            foreach ($owners as $ownerIndex => $owner) {
                $session = Uuid::generate()->toString();
                $this->connection->executeStatement('INSERT INTO transcode_sessions (id, public_id, user_id, job_id, video_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, clock_timestamp(), clock_timestamp())', [$session, (new PublicId())->toString(), $owner, $jobs[$index], $videos[$index]]);
                if ($index === 0 && $ownerIndex === 0) {
                    $runtimeSession = $session;
                }
            }
        }
        $this->connection->executeStatement("UPDATE transcode_jobs SET status = 'completed', reference_count = 2, total_segments = 2, completed_segments = 2 WHERE id = ?", [$jobs[0]]);
        $this->connection->executeStatement("UPDATE transcode_sessions SET state = 'active' WHERE id = ?", [$runtimeSession]);
        $desired = json_encode(['status' => 'paused', 'position' => 10.0, 'segmentIndex' => 0, 'audioLanguages' => ['en'], 'audioProfile' => \App\Transcode\Domain\ValueObject\AudioProfile::streamingStereo()->jsonSerialize()], JSON_THROW_ON_ERROR);
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO transcode_runtime_state (job_id, session_id, request_id, last_control_request_id, desired, desired_revision, observed_revision, generation, outcome, output_directory, admission_deadline, worker_namespace, worker_boot_id, worker_id, worker_launch_generation, worker_lease_epoch, claim_token, lease_expires_at, updated_at)
VALUES (?, ?, 'start-upgrade', 'seek-upgrade', CAST(? AS jsonb), 2, 2, 7, 'complete', '/old-output/generation-7', clock_timestamp() - interval '1 second', 'baander.app:workers', ?, 'media', 1, 1, 'old-claim', clock_timestamp() + interval '1 hour', clock_timestamp())
SQL, [$jobs[0], $runtimeSession, $desired, str_repeat('a', 32)]);

        self::assertSame(0, $this->migrate($this->factory()));

        $owners = $this->connection->fetchAllKeyValue('SELECT id::text, owner_user_id::text FROM transcode_jobs');
        self::assertSame($users[0], $owners[$jobs[0]], 'The runtime session establishes an owner even when older attachments have other users.');
        self::assertSame($users[0], $owners[$jobs[1]], 'One distinct session owner establishes ownership without a runtime.');
        self::assertNull($owners[$jobs[2]], 'Ambiguous history must remain unowned.');
        self::assertNull($owners[$jobs[3]], 'A job without sessions must remain unowned.');
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT count(*) FROM transcode_jobs'));
        self::assertSame(6, (int) $this->connection->fetchOne('SELECT count(*) FROM transcode_sessions'));
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM transcode_jobs WHERE id = ?', [$jobs[0]]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT reference_count FROM transcode_jobs WHERE id = ?', [$jobs[0]]));
        self::assertSame('seek-upgrade', $this->connection->fetchOne('SELECT request_id FROM transcode_control_receipts WHERE session_id = ?', [$runtimeSession]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'transcode_runtime_state' AND column_name = 'last_control_request_id'"));
        $runtime = $this->connection->fetchAssociative('SELECT * FROM transcode_runtime_state WHERE job_id = ?', [$jobs[0]]);
        self::assertIsArray($runtime);
        self::assertSame('accepted', $runtime['outcome']);
        self::assertSame(8, $runtime['generation']);
        self::assertSame(0, $runtime['observed_revision']);
        self::assertSame(2, $runtime['desired_revision']);
        self::assertEquals(json_decode($desired, true, 512, JSON_THROW_ON_ERROR), json_decode($runtime['desired'], true, 512, JSON_THROW_ON_ERROR));
        self::assertNull($runtime['output_directory']);
        self::assertNull($runtime['claim_token']);
        self::assertNull($runtime['worker_boot_id']);
        self::assertNull($runtime['lease_expires_at']);
        $this->connection->executeStatement("INSERT INTO transcode_jobs (id, public_id, video_id, owner_user_id, quality_tier_name, created_at, updated_at) VALUES (?, ?, ?, ?, '720p', clock_timestamp(), clock_timestamp())", [Uuid::generate()->toString(), (new PublicId())->toString(), $videos[0], $users[1]]);
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT count(*) FROM transcode_jobs'));
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
        self::assertSame(6, (int) $this->connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
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

    public function testCompletedSingletonUpgradePreservesOriginalEvidenceAsTerminalImportedBoot(): void
    {
        self::assertSame(0, $this->migrate($this->factory(), 'DoctrineMigrations\\Version20261017170000'));
        $namespace = 'baander.app:upgrade';
        $boot = str_repeat('a', 32);
        $daemon = 'baander.app:daemon';
        $container = str_repeat('c', 64);
        $recipe = str_repeat('f', 64);
        $created = '2026-10-17 10:20:30.123456+00';
        $intended = '2026-10-17 10:21:30.234567+00';
        $completed = '2026-10-17 10:22:30.345678+00';
        $this->connection->executeStatement('INSERT INTO worker_deployment_creations (namespace,boot_id,daemon_id,recipe_hash,created_at) VALUES (?,?,?,?,?)', [$namespace,$boot,$daemon,$recipe,$created]);
        $this->connection->executeStatement('INSERT INTO worker_deployment_containers (namespace,boot_id,daemon_id,container_id,created_at,start_claimed_at) VALUES (?,?,?,?,?,?)', [$namespace,$boot,$daemon,$container,$created,$created]);
        $this->connection->executeStatement('INSERT INTO worker_deployment_retirements (namespace,boot_id,daemon_id,container_id,intended_at,completed_at) VALUES (?,?,?,?,?,?)', [$namespace,$boot,$daemon,$container,$intended,$completed]);
        $this->connection->executeStatement("INSERT INTO worker_deployment_leases (namespace,owner_boot_id,epoch,state,expires_at) VALUES (?,?,3,'available',?)", [$namespace,$boot,$completed]);
        $before = [];
        foreach (['creations','containers','retirements','leases'] as $table) {
            $before[$table] = $this->connection->fetchAssociative('SELECT * FROM worker_deployment_' . $table);
        }
        self::assertSame(0, $this->migrate($this->factory()));
        foreach (['creations','containers','retirements'] as $table) {
            $row = $this->connection->fetchAssociative("SELECT * FROM worker_deployment_" . $table . " WHERE unit = 'control'");
            self::assertIsArray($row);
            foreach ($before[$table] as $column => $value) {
                self::assertSame($value, $row[$column], $table . '.' . $column);
            }
        }
        self::assertSame($before['leases'], $this->connection->fetchAssociative('SELECT * FROM worker_deployment_leases'));
        $inventory = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($this->connection);
        $snapshot = $inventory->findBoot($namespace, $boot);
        self::assertNotNull($snapshot);
        self::assertTrue($snapshot->importedSingleton);
        self::assertTrue($snapshot->retired);
        self::assertTrue($snapshot->retirementRequested);
        self::assertNull($snapshot->manifestHash);
        self::assertSame($recipe, $snapshot->control->recipeHash);
        self::assertTrue($snapshot->control->retirement?->completed);
        self::assertTrue($snapshot->execution->cancelled);
        self::assertFalse($snapshot->execution->createClaimed);
        self::assertNull($snapshot->execution->binding);
        self::assertNull($snapshot->execution->recipeHash);
        self::assertNull((new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($this->connection))->acquire($namespace, $boot, 60));
        self::assertNotNull($snapshot->control->binding);
        self::assertFalse($inventory->claimStart($snapshot->control->binding));
        self::assertSame(0, $this->migrate($this->factory()));
    }

    #[DataProvider('unresolvedSingletons')]
    public function testSingletonUpgradeRejectsUnresolvedOrInconsistentEvidenceWithoutMutation(string $kind): void
    {
        self::assertSame(0, $this->migrate($this->factory(), 'DoctrineMigrations\\Version20261017170000'));
        $namespace = 'baander.app:blocked-upgrade';
        $boot = str_repeat('a', 32);
        if ($kind === 'active lease') {
            $this->connection->executeStatement("INSERT INTO worker_deployment_leases (namespace,owner_boot_id,epoch,state,expires_at) VALUES (?,?,1,'active',clock_timestamp())", [$namespace,$boot]);
        } else {
            if ($kind !== 'completed binding without recipe') {
                $this->connection->executeStatement('INSERT INTO worker_deployment_creations (namespace,boot_id,daemon_id,recipe_hash) VALUES (?,?,?,?)', [$namespace,$boot,'baander.app:daemon',str_repeat('f',64)]);
            }
            if ($kind !== 'uncertain unbound create') {
                $this->connection->executeStatement('INSERT INTO worker_deployment_containers (namespace,boot_id,daemon_id,container_id) VALUES (?,?,?,?)', [$namespace,$boot,'baander.app:daemon',str_repeat('c',64)]);
            }
            if ($kind === 'completed binding without recipe') {
                $this->connection->executeStatement('INSERT INTO worker_deployment_retirements (namespace,boot_id,daemon_id,container_id,completed_at) VALUES (?,?,?,?,clock_timestamp())', [$namespace,$boot,'baander.app:daemon',str_repeat('c',64)]);
            }
        }
        $snapshot = function (): array {
            $rows = [];
            foreach (['worker_deployment_creations','worker_deployment_containers','worker_deployment_retirements','worker_deployment_leases','scheduled_jobs','doctrine_migration_versions'] as $table) {
                $rows[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $table);
            }
            return $rows;
        };
        $before = $snapshot();
        $catalog = $this->catalog('public');
        $failure = $this->refused($this->factory());
        self::assertStringContainsString('Unresolved or inconsistent singleton deployments', $failure);
        self::assertStringContainsString($namespace . '/' . $boot, $failure);
        self::assertSame($before, $snapshot());
        self::assertSame($catalog, $this->catalog('public'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('worker_deployment_boots')::text"));
    }

    /** @return iterable<string,array{string}> */
    public static function unresolvedSingletons(): iterable
    {
        foreach (['active lease', 'uncertain unbound create', 'binding without removal proof', 'completed binding without recipe'] as $kind) {
            yield $kind => [$kind];
        }
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
