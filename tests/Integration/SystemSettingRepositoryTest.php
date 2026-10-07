<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Doctrine\Entity\SystemSettingEntity;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Repository\SystemSettingRepository;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use DoctrineMigrations\Version20261007110000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The DBAL system settings store on the migrated disposable PostgreSQL database.
 *
 * Most tests run in the harness's rolled-back transaction. Commit visibility and statement
 * atomicity use independent connections and committed rows with per-test keys, removed afterwards.
 */
final class SystemSettingRepositoryTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
        tearDown as private tearDownHarness;
    }

    private SystemSettingRepository $store;

    /** @var list<Connection> */
    private array $independent = [];

    /** Keys committed through independent connections, deleted in tearDown. */
    private string $committedKey;

    protected function setUp(): void
    {
        $this->setUpHarness();
        $this->store = new SystemSettingRepository($this->manager->getConnection());
        $this->committedKey = 'test.committed_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        foreach ($this->independent as $index => $connection) {
            if ($index === 0) {
                $connection->executeStatement('DELETE FROM system_settings WHERE key LIKE :prefix', ['prefix' => $this->committedKey . '%']);
            }
            $connection->close();
        }
    }

    public function testSaveKeepsTheExactTypeOfEveryValue(): void
    {
        $values = [
            'test.bitrate' => 192,
            'test.bitrate_text' => '192',
            'test.disabled' => false,
            'test.empty' => '',
            'test.enabled' => true,
            'test.language' => 'da',
            'test.negative' => -1,
            'test.unicode' => 'Bånd "ære" \\ /',
            'test.zero' => 0,
        ];

        $this->store->save($values);

        self::assertSame($values, array_filter(
            $this->store->all(),
            static fn (string $key): bool => str_starts_with($key, 'test.'),
            ARRAY_FILTER_USE_KEY,
        ));
        foreach ($values as $key => $value) {
            self::assertSame($value, $this->store->find($key), $key);
        }
        self::assertSame(
            ['test.bitrate' => 'number', 'test.bitrate_text' => 'string', 'test.enabled' => 'boolean'],
            $this->manager->getConnection()->fetchAllKeyValue(
                "SELECT key, jsonb_typeof(value) FROM system_settings WHERE key IN ('test.bitrate', 'test.bitrate_text', 'test.enabled') ORDER BY key",
            ),
        );
    }

    public function testSaveReplacesAStoredValueAndKeepsTheOthers(): void
    {
        $this->store->save(['test.bitrate' => 192, 'test.enabled' => true]);
        $this->manager->getConnection()->executeStatement(
            "UPDATE system_settings SET updated_at = '2026-01-01 00:00:00+00' WHERE key LIKE 'test.%'",
        );

        $this->store->save(['test.bitrate' => '320']);

        self::assertSame('320', $this->store->find('test.bitrate'));
        self::assertTrue($this->store->find('test.enabled'));
        self::assertSame(
            ['test.bitrate' => true, 'test.enabled' => false],
            array_map(
                static fn (mixed $touched): bool => (bool) $touched,
                $this->manager->getConnection()->fetchAllKeyValue(
                    "SELECT key, updated_at > '2026-01-01 00:00:00+00' FROM system_settings WHERE key LIKE 'test.%' ORDER BY key",
                ),
            ),
        );
    }

    public function testAnUnsetKeyReadsAsNullAndDeletingItSucceeds(): void
    {
        self::assertNull($this->store->find('test.unset'));
        self::assertArrayNotHasKey('test.unset', $this->store->all());

        $this->store->delete('test.unset');
        $this->store->save([]);

        self::assertNull($this->store->find('test.unset'));
    }

    public function testDeleteRemovesOnlyThatKey(): void
    {
        $this->store->save(['test.enabled' => false, 'test.language' => 'en']);

        $this->store->delete('test.enabled');

        self::assertNull($this->store->find('test.enabled'));
        self::assertArrayNotHasKey('test.enabled', $this->store->all());
        self::assertSame('en', $this->store->find('test.language'));
    }

    public function testASaveThatFailsForOneValueWritesNone(): void
    {
        // Autocommit connections: the save is not inside any outer transaction.
        $writer = $this->independentConnection();
        $observer = $this->independentConnection();
        $store = new SystemSettingRepository($writer);
        $store->save([$this->committedKey . '_kept' => 'en']);

        try {
            // PostgreSQL's jsonb rejects \u0000, which json_encode emits for a NUL byte.
            $store->save([
                $this->committedKey . '_kept' => 'da',
                $this->committedKey . '_valid' => true,
                $this->committedKey . '_invalid' => "nul\0byte",
            ]);
            self::fail('A NUL byte cannot be stored in jsonb.');
        } catch (DriverException $exception) {
            self::assertSame('22P05', $exception->getSQLState());
        }

        self::assertSame(
            [$this->committedKey . '_kept' => '"en"'],
            $observer->fetchAllKeyValue('SELECT key, value::text FROM system_settings WHERE key LIKE :prefix ORDER BY key', ['prefix' => $this->committedKey . '%']),
        );
    }

    public function testReadsSeeAValueAnotherConnectionCommittedWithoutClearingAnything(): void
    {
        $other = $this->independentConnection();
        $key = $this->committedKey;
        $other->executeStatement("INSERT INTO system_settings (key, value) VALUES (:key, '\"en\"')", ['key' => $key]);

        // The identity map now holds the first value.
        $managed = $this->manager->find(SystemSettingEntity::class, $key);
        self::assertInstanceOf(SystemSettingEntity::class, $managed);
        self::assertSame('en', $managed->getValue());
        self::assertSame('en', $this->store->find($key));

        $other->executeStatement("UPDATE system_settings SET value = '\"da\"' WHERE key = :key", ['key' => $key]);

        self::assertSame('da', $this->store->find($key));
        self::assertSame('da', $this->store->all()[$key]);
        self::assertSame('en', $this->manager->find(SystemSettingEntity::class, $key)?->getValue(), 'the ORM identity map keeps the stale value');

        $other->executeStatement('DELETE FROM system_settings WHERE key = :key', ['key' => $key]);

        self::assertNull($this->store->find($key));
    }

    public function testUpdatedAtIsATimestamptzThatDefaultsToNow(): void
    {
        self::assertSame(
            ['data_type' => 'timestamp with time zone', 'column_default' => 'now()', 'is_nullable' => 'NO'],
            $this->manager->getConnection()->fetchAssociative(
                "SELECT data_type, column_default, is_nullable FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = 'system_settings' AND column_name = 'updated_at'",
            ),
        );
        $this->assertSchemaComparisonIsClean(['system_settings']);
    }

    public function testTheMigrationKeepsValuesAndReadsOldInstantsAsUtc(): void
    {
        $connection = $this->manager->getConnection();
        $this->migrate('down');
        self::assertSame('timestamp without time zone', $this->updatedAtType());

        // PHP and the database session wrote UTC wall-clock times. A session zone far from UTC
        // shows that the conversion names the zone instead of using the session's.
        $connection->executeStatement("SET LOCAL TIME ZONE 'Pacific/Kiritimati'");
        $connection->executeStatement(
            "INSERT INTO system_settings (key, value, updated_at) VALUES
                ('test.bitrate', '192', '2026-10-07 23:59:59.999999'),
                ('test.enabled', 'true', '2026-10-07 10:00:00'),
                ('test.language', '\"da\"', '2026-10-07 10:00:00.5')",
        );

        $this->migrate('up');

        self::assertSame('timestamp with time zone', $this->updatedAtType());
        $connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");
        self::assertSame([
            'test.bitrate' => ['value' => '192', 'updated_at' => '2026-10-07 23:59:59.999999+00'],
            'test.enabled' => ['value' => 'true', 'updated_at' => '2026-10-07 10:00:00+00'],
            'test.language' => ['value' => '"da"', 'updated_at' => '2026-10-07 10:00:00.5+00'],
        ], $connection->fetchAllAssociativeIndexed(
            "SELECT key, value::text AS value, updated_at::text AS updated_at FROM system_settings WHERE key LIKE 'test.%' ORDER BY key",
        ));
        self::assertSame(['test.bitrate' => 192, 'test.enabled' => true, 'test.language' => 'da'], array_filter(
            $this->store->all(),
            static fn (string $key): bool => str_starts_with($key, 'test.'),
            ARRAY_FILTER_USE_KEY,
        ));

        $this->migrate('down');

        self::assertSame(
            ['test.bitrate' => '2026-10-07 23:59:59.999999', 'test.enabled' => '2026-10-07 10:00:00', 'test.language' => '2026-10-07 10:00:00.5'],
            $connection->fetchAllKeyValue("SELECT key, updated_at::text FROM system_settings WHERE key LIKE 'test.%' ORDER BY key"),
        );

        $this->migrate('up');

        $this->assertSchemaComparisonIsClean(['system_settings']);
    }

    private function updatedAtType(): string
    {
        $type = $this->manager->getConnection()->fetchOne(
            "SELECT data_type FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'system_settings' AND column_name = 'updated_at'",
        );
        self::assertIsString($type);

        return $type;
    }

    private function migrate(string $direction): void
    {
        $connection = $this->manager->getConnection();
        require_once dirname(__DIR__, 2) . '/migrations/Version20261007110000.php';
        $migration = new Version20261007110000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function independentConnection(): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertIsString($url);
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware()]);
        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url), $configuration);
        $this->independent[] = $connection;

        return $connection;
    }
}
