<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Repository\SystemSettingRepository;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Domain\ValueObject\AacImplementation;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261113120000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The AAC implementation value `baac` became `bac`. The migration rewrites the stored value in
 * the setting, in failed audio renditions and in the desired state of media runtime rows, so the
 * runtime readers, which refuse an unknown value, keep reading every row after an upgrade.
 */
final class AacImplementationValueMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261113120000';
    private const string SETTING = TranscodeSettingDefinitions::AAC_IMPLEMENTATION;
    private const string CONSTRAINT = 'chk_audio_rendition_runtime_failed_aac_implementation';
    private const array ACTIVE_OUTCOMES = ['pending', 'accepted', 'ready'];
    private const array TERMINAL_OUTCOMES = ['complete', 'failed', 'cancelled', 'refused'];
    /** One more active row than a worker poll reads, so the poll comes back full. */
    private const int ACTIVE_FORMER_ROWS = 101;
    private const array FORMER_RENDITIONS = ['failed-former-1', 'failed-former-2', 'failed-former-3'];
    /** Rendition rows that do not hold the former value, by key: state and recorded implementation. */
    private const array OTHER_RENDITIONS = [
        'failed-fdk-aac' => ['failed', 'fdk_aac'],
        'failed-ffmpeg' => ['failed', 'ffmpeg'],
        'failed-unrecorded' => ['failed', null],
        'pending' => ['pending', null],
        'running' => ['running', null],
        'complete' => ['complete', null],
    ];

    private Connection $connection;
    private string $schema;
    private Uuid $userId;
    private Uuid $videoId;
    private int $requests = 0;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'aac_value_migration_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->connection->executeStatement("SET TIME ZONE 'UTC'");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testStoredFormerValuesReadAsTheRenamedValueAfterMigrating(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedOwner();
        $this->storeSetting('baac');
        $former = $this->seedFormerRuntimeRows();
        $this->seedRenditions();
        $renditions = new AudioRenditionRuntime($this->connection);

        self::assertRefusesTheFormerValue(fn (): array => $this->runtime()->pending());
        self::assertRefusesTheFormerValue(fn (): array => $this->runtime()->findGroup($former));
        self::assertRefusesTheFormerValue(static fn (): mixed => $renditions->find(self::FORMER_RENDITIONS[0]));
        self::assertFalse($this->settings()->entry(self::SETTING)->storedValueValid);
        $unchanged = $this->rowsApartFromTheValue();

        $this->migrate('up');

        self::assertSame('"bac"', $this->storedSetting());
        self::assertTrue($this->settings()->entry(self::SETTING)->storedValueValid);
        self::assertSame(AacImplementation::Bac, AacImplementation::from((string) $this->settings()->get(self::SETTING)));

        $poll = $this->runtime()->pending();
        self::assertCount(100, $poll);
        self::assertSame(['bac'], self::implementations($poll));
        $all = $this->runtime()->findGroup($former);
        self::assertCount(count($former), $all);
        self::assertSame(['bac'], self::implementations($all));
        $outcomes = array_values(array_unique(array_map(static fn (TranscodeRuntimeSnapshot $row): string => $row->outcome, $all)));
        self::assertEqualsCanonicalizing([...self::ACTIVE_OUTCOMES, ...self::TERMINAL_OUTCOMES], $outcomes);

        foreach (self::FORMER_RENDITIONS as $key) {
            $rendition = $renditions->find($key);
            self::assertNotNull($rendition, $key);
            self::assertSame('failed', $rendition->state, $key);
            self::assertSame(AacImplementation::Bac, $rendition->failedAacImplementation, $key);
        }
        self::assertSame($unchanged, $this->rowsApartFromTheValue(), 'The rewrite changes the value and nothing else, not even updated_at.');
    }

    #[DataProvider('otherSettingValues')]
    public function testRowsWithoutTheFormerValueAreNotRewritten(string $setting, AacImplementation $implementation): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedOwner();
        $this->storeSetting($setting);
        $this->seedFormerRuntimeRows();
        $others = [
            'fdk_aac' => $this->runtimeRow('pending', ['aacImplementation' => 'fdk_aac']),
            'ffmpeg' => $this->runtimeRow('complete', ['aacImplementation' => 'ffmpeg']),
            'null' => $this->runtimeRow('ready', ['aacImplementation' => null]),
            'absent' => $this->runtimeRow('failed', []),
        ];
        $this->seedRenditions();
        $versions = $this->rowVersions($others);

        $this->migrate('up');

        self::assertSame($versions, $this->rowVersions($others), 'The migration leaves these rows unwritten.');
        self::assertSame($setting, $this->settings()->get(self::SETTING));
        self::assertSame($implementation, AacImplementation::from((string) $this->settings()->get(self::SETTING)));
        $snapshots = $this->runtime()->findGroup(array_values($others));
        self::assertSame(AacImplementation::FdkAac, $snapshots[$others['fdk_aac']->toString()]->desired->aacImplementation);
        self::assertSame(AacImplementation::Ffmpeg, $snapshots[$others['ffmpeg']->toString()]->desired->aacImplementation);
        self::assertNull($snapshots[$others['null']->toString()]->desired->aacImplementation);
        self::assertNull($snapshots[$others['absent']->toString()]->desired->aacImplementation);
        $renditions = new AudioRenditionRuntime($this->connection);
        foreach (self::OTHER_RENDITIONS as $key => [$state, $value]) {
            $rendition = $renditions->find($key);
            self::assertNotNull($rendition, $key);
            self::assertSame($state, $rendition->state, $key);
            self::assertSame($value, $rendition->failedAacImplementation?->value, $key);
        }

        $this->migrate('down');

        self::assertSame($versions, $this->rowVersions($others), 'Reverting leaves these rows unwritten.');
    }

    /** @return iterable<string, array{string, AacImplementation}> */
    public static function otherSettingValues(): iterable
    {
        yield 'fdk-aac' => ['fdk_aac', AacImplementation::FdkAac];
        yield 'FFmpeg built-in' => ['ffmpeg', AacImplementation::Ffmpeg];
    }

    public function testAnInstanceWithoutTheSettingKeepsUsingTheDefault(): void
    {
        $this->migrateToTheFormerRelease();

        $this->migrate('up');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM system_settings WHERE key = ?', [self::SETTING]));
        $entry = $this->settings()->entry(self::SETTING);
        self::assertNull($entry->storedValue);
        self::assertSame('bac', $entry->value);
        self::assertSame(AacImplementation::Bac, AacImplementation::from((string) $this->settings()->get(self::SETTING)));
    }

    public function testTheConstraintAcceptsOnlyTheRenamedValueAfterMigrating(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedRenditions();
        $this->assertViolatesTheConstraint(fn () => $this->rendition('renamed-too-early', 'failed', 'bac'));

        $this->migrate('up');

        $this->assertAcceptsOnlyTheRenamedValues();
    }

    public function testDownRestoresTheFormerValuesAndConstraint(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedOwner();
        $this->storeSetting('baac');
        $former = $this->seedFormerRuntimeRows();
        $this->seedRenditions();
        $constraint = $this->constraintDefinition();

        $this->migrate('up');
        $this->rendition('failed-after-the-rename', 'failed', 'bac');
        $this->migrate('down');

        self::assertSame('"baac"', $this->storedSetting());
        $formerRenditions = [...self::FORMER_RENDITIONS, 'failed-after-the-rename'];
        sort($formerRenditions, SORT_STRING);
        self::assertSame($formerRenditions, $this->connection->fetchFirstColumn("SELECT rendition_key FROM audio_rendition_runtime WHERE failed_aac_implementation = 'baac' ORDER BY rendition_key COLLATE \"C\""));
        self::assertSame(count($former), (int) $this->connection->fetchOne(<<<'SQL'
SELECT count(*) FROM transcode_runtime_state WHERE desired->'aacImplementation' = '"baac"'::jsonb
SQL));
        self::assertSame(0, (int) $this->connection->fetchOne(<<<'SQL'
SELECT (SELECT count(*) FROM transcode_runtime_state WHERE desired->'aacImplementation' = '"bac"'::jsonb)
     + (SELECT count(*) FROM audio_rendition_runtime WHERE failed_aac_implementation = 'bac')
     + (SELECT count(*) FROM system_settings WHERE value = '"bac"'::jsonb)
SQL));
        self::assertSame($constraint, $this->constraintDefinition());
        $this->rendition('failed-former-again', 'failed', 'baac');
        $this->assertViolatesTheConstraint(fn () => $this->rendition('renamed-after-revert', 'failed', 'bac'));
    }

    public function testMigrationsOnAnEmptyDatabaseEndAllowingTheRenamedValues(): void
    {
        FinalSchemaBootstrap::bootstrap($this->connection);

        $this->assertAcceptsOnlyTheRenamedValues();
    }

    public function testTheRewriteRecordsOneLiveChangePerRewrittenRow(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedOwner();
        $this->storeSetting('baac');
        $former = $this->seedFormerRuntimeRows();
        $this->runtimeRow('pending', ['aacImplementation' => 'fdk_aac']);
        $this->runtimeRow('ready', []);
        $this->seedRenditions();
        $seeded = (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');

        $this->migrate('up');

        $changes = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT family, scope_kind, scope_id, count(*) AS changes
FROM query_change_inbox
WHERE id > ?
GROUP BY family, scope_kind, scope_id
ORDER BY family COLLATE "C", scope_kind COLLATE "C", scope_id COLLATE "C"
SQL, [$seeded]);
        self::assertSame([
            ['admin-media', 'admin', '*', count($former) + count(self::FORMER_RENDITIONS)],
            ['admin-metadata', 'admin', '*', 1],
            ['admin-settings', 'admin', '*', 1],
            ['recommendations', 'user', $this->userId->toString(), 1],
        ], array_map(static fn (array $row): array => [$row['family'], $row['scope_kind'], $row['scope_id'], (int) $row['changes']], $changes));
    }

    /** Builds the schema the release before the rename left: every migration before the one under test. */
    private function migrateToTheFormerRelease(): void
    {
        $files = (glob(dirname(__DIR__, 2) . '/migrations/Version*.php') ?: [])
            |> (static fn (array $files): array => array_filter($files, self::precedesTheMigration(...)))
            |> array_values(...);
        sort($files, SORT_STRING);
        self::assertNotSame([], $files);
        $this->connection->transactional(function () use ($files): void {
            foreach ($files as $file) {
                require_once $file;
                $class = 'DoctrineMigrations\\' . basename($file, '.php');
                $migration = new $class($this->connection, new NullLogger());
                self::assertInstanceOf(AbstractMigration::class, $migration);
                $migration->up(new Schema());
                $this->execute($migration);
            }
        });
    }

    private static function precedesTheMigration(string $file): bool
    {
        return strcmp(basename($file, '.php'), self::MIGRATION) < 0;
    }

    /** Runs the migration under test in one transaction, as Doctrine Migrations does. */
    private function migrate(string $direction): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php';
        $migration = new Version20261113120000($this->connection, new NullLogger());
        if ($direction === 'up') {
            $migration->up(new Schema());
        } else {
            $migration->down(new Schema());
        }
        $this->connection->transactional(fn () => $this->execute($migration));
    }

    /** Runs the planned statements; extensions are provisioned in the public schema, as FinalSchemaBootstrap expects. */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($query->getStatement())) === 1) {
                continue;
            }
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function seedOwner(): void
    {
        $this->userId = Uuid::generate();
        $this->videoId = Uuid::generate();
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO users (id, public_id, name, email, password, roles, created_at, updated_at)
VALUES (?, ?, 'Media administrator', 'aac-migration@baander.app', 'hash', '["ROLE_USER", "ROLE_ADMIN"]', clock_timestamp(), clock_timestamp())
SQL, [$this->userId->toString(), (new PublicId())->toString()]);
        $this->connection->executeStatement("INSERT INTO videos (id, public_id, path, hash, created_at, updated_at) VALUES (?, ?, '/fixture/video.mp4', 'fixture', clock_timestamp(), clock_timestamp())", [$this->videoId->toString(), (new PublicId())->toString()]);
    }

    private function storeSetting(string $value): void
    {
        $this->connection->executeStatement('INSERT INTO system_settings (key, value) VALUES (?, CAST(? AS jsonb))', [self::SETTING, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Runtime rows holding the former value in every outcome, with more active rows than one poll reads.
     *
     * @return list<Uuid>
     */
    private function seedFormerRuntimeRows(): array
    {
        $active = static fn (int $row): string => self::ACTIVE_OUTCOMES[$row % count(self::ACTIVE_OUTCOMES)];
        $outcomes = [...array_map($active, range(0, self::ACTIVE_FORMER_ROWS - 1)), ...self::TERMINAL_OUTCOMES];
        return array_map(fn (string $outcome): Uuid => $this->runtimeRow($outcome, ['aacImplementation' => 'baac']), $outcomes);
    }

    /** @param array{aacImplementation?: string|null} $implementation */
    private function runtimeRow(string $outcome, array $implementation): Uuid
    {
        $job = Uuid::generate();
        $session = Uuid::generate();
        $request = 'request-' . ++$this->requests;
        $this->connection->executeStatement("INSERT INTO transcode_jobs (id, public_id, video_id, owner_user_id, request_id, quality_tier_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '1080p', clock_timestamp(), clock_timestamp())", [$job->toString(), (new PublicId())->toString(), $this->videoId->toString(), $this->userId->toString(), $request]);
        $this->connection->executeStatement('INSERT INTO transcode_sessions (id, public_id, user_id, job_id, video_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, clock_timestamp(), clock_timestamp())', [$session->toString(), (new PublicId())->toString(), $this->userId->toString(), $job->toString(), $this->videoId->toString()]);
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO transcode_runtime_state (job_id, session_id, request_id, desired, desired_revision, observed_revision, generation, outcome, admission_deadline, updated_at)
VALUES (?, ?, ?, CAST(? AS jsonb), 1, 0, 1, ?, clock_timestamp() + interval '5 seconds', clock_timestamp())
SQL, [$job->toString(), $session->toString(), $request, self::desired($implementation), $outcome]);
        return $job;
    }

    /**
     * The desired state document the runtime writes; an absent implementation key is how rows
     * from before the AAC implementation setting were stored.
     *
     * @param array{aacImplementation?: string|null} $implementation
     */
    private static function desired(array $implementation): string
    {
        return json_encode(['status' => 'running', 'position' => 0.0, 'segmentIndex' => 0, 'audioProfile' => AudioProfile::streamingStereo()->jsonSerialize(), 'audioLanguages' => [], 'presentationSelection' => null] + $implementation, JSON_THROW_ON_ERROR);
    }

    private function seedRenditions(): void
    {
        foreach (self::FORMER_RENDITIONS as $key) {
            $this->rendition($key, 'failed', 'baac');
        }
        foreach (self::OTHER_RENDITIONS as $key => [$state, $implementation]) {
            $this->rendition($key, $state, $implementation);
        }
    }

    private function rendition(string $key, string $state, ?string $implementation): void
    {
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO audio_rendition_runtime (rendition_key, track_key, source_signature, source_path, format, bitrate, output_directory, state, generation, error, failed_aac_implementation, updated_at)
VALUES (?, 'baander-track', ?, '/fixture/source.flac', 'aac', 256000, '/fixture/derived/track', ?, 1, ?, ?, clock_timestamp())
SQL, [$key, $key, $state, $state === 'failed' ? 'the encoder failed' : null, $implementation]);
    }

    private function runtime(): TranscodeRuntimeState
    {
        $transaction = new class($this->connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}
            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };
        return new TranscodeRuntimeState($this->connection, $transaction);
    }

    private function settings(): SystemSettings
    {
        return new SystemSettings(new SettingDefinitionRegistry([new TranscodeSettingDefinitions()]), new SystemSettingRepository($this->connection));
    }

    private function storedSetting(): string
    {
        return (string) $this->connection->fetchOne('SELECT value::text FROM system_settings WHERE key = ?', [self::SETTING]);
    }

    /**
     * Every row of the three tables with the AAC implementation value removed, by row.
     *
     * @return array<string, string>
     */
    private function rowsApartFromTheValue(): array
    {
        return $this->connection->fetchAllKeyValue(<<<'SQL'
SELECT row_name, content FROM (
    SELECT 'runtime ' || job_id AS row_name, ((to_jsonb(r) - 'desired') || jsonb_build_object('desired', desired - 'aacImplementation'))::text AS content FROM transcode_runtime_state r
    UNION ALL SELECT 'rendition ' || rendition_key, (to_jsonb(a) - 'failed_aac_implementation')::text FROM audio_rendition_runtime a
    UNION ALL SELECT 'setting ' || key, (to_jsonb(s) - 'value')::text FROM system_settings s
) stored_rows
ORDER BY row_name COLLATE "C"
SQL);
    }

    /**
     * The tuple version and content of the given runtime rows, every non-former rendition and the
     * setting. An unchanged xmin shows that no statement wrote the row, even with an equal value.
     *
     * @param array<string, Uuid> $jobs
     * @return array<string, string>
     */
    private function rowVersions(array $jobs): array
    {
        return $this->connection->fetchAllKeyValue(<<<'SQL'
SELECT row_name, content FROM (
    SELECT 'runtime ' || job_id AS row_name, xmin::text || ' ' || to_jsonb(r)::text AS content FROM transcode_runtime_state r WHERE job_id::text IN (?)
    UNION ALL SELECT 'rendition ' || rendition_key, xmin::text || ' ' || to_jsonb(a)::text FROM audio_rendition_runtime a WHERE rendition_key IN (?)
    UNION ALL SELECT 'setting ' || key, xmin::text || ' ' || to_jsonb(s)::text FROM system_settings s
) stored_rows
ORDER BY row_name COLLATE "C"
SQL, [
            array_values(array_map(static fn (Uuid $job): string => $job->toString(), $jobs)),
            array_keys(self::OTHER_RENDITIONS),
        ], [ArrayParameterType::STRING, ArrayParameterType::STRING]);
    }

    private function constraintDefinition(): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'audio_rendition_runtime'::regclass AND conname = ?",
            [self::CONSTRAINT],
        );
    }

    private function assertAcceptsOnlyTheRenamedValues(): void
    {
        foreach (AacImplementation::cases() as $implementation) {
            $this->rendition('accepted-' . $implementation->value, 'failed', $implementation->value);
        }
        self::assertSame(['bac', 'fdk_aac', 'ffmpeg'], $this->connection->fetchFirstColumn("SELECT failed_aac_implementation FROM audio_rendition_runtime WHERE rendition_key LIKE 'accepted-%' ORDER BY failed_aac_implementation COLLATE \"C\""));
        $this->assertViolatesTheConstraint(fn () => $this->rendition('rejected-former', 'failed', 'baac'));
        $this->assertViolatesTheConstraint(fn () => $this->rendition('rejected-unfailed', 'pending', 'bac'));
    }

    private function assertViolatesTheConstraint(\Closure $insert): void
    {
        try {
            $insert();
        } catch (DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
            self::assertStringContainsString(self::CONSTRAINT, $error->getMessage());
            return;
        }
        self::fail('The insert satisfied ' . self::CONSTRAINT . '.');
    }

    private static function assertRefusesTheFormerValue(\Closure $read): void
    {
        try {
            $read();
        } catch (\ValueError $error) {
            self::assertStringContainsString('"baac"', $error->getMessage());
            return;
        }
        self::fail('The reader accepted the former value.');
    }

    /**
     * The distinct implementation values the snapshots hold, `none` for a desired state without one.
     *
     * @param array<array-key, TranscodeRuntimeSnapshot> $snapshots
     * @return list<string>
     */
    private static function implementations(array $snapshots): array
    {
        return array_values(array_unique(array_map(static fn (TranscodeRuntimeSnapshot $row): string => $row->desired->aacImplementation->value ?? 'none', $snapshots)));
    }
}
