<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261010180000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Version20261010180000 stores every instant column as timestamptz with microseconds: it relaxes
 * timestamptz(0) and reads zone-less values as UTC, whatever the session's zone.
 */
final class InstantColumnMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
    }

    private const string WEBHOOK = '00000000-0000-7000-8000-00000000a001';

    protected function setUp(): void
    {
        $this->setUpHarness();
        // Literal instants below are UTC, and so is their text form in the assertions.
        $this->manager->getConnection()->executeStatement("SET LOCAL TIME ZONE 'UTC'");
    }

    public function testUpgradeKeepsEveryInstantAndEveryRow(): void
    {
        $connection = $this->manager->getConnection();
        $this->migrate('down');
        self::assertSame('timestamp(0) without time zone', $this->type('webhooks', 'created_at'));
        self::assertSame('timestamp(0) with time zone', $this->type('notifications', 'created_at'));
        $connection->executeStatement(
            "INSERT INTO webhooks (id, url, encrypted_secret, created_at, updated_at)
             VALUES (:id, 'https://hooks.baander.app/migration', 'secret', TIMESTAMP '2026-03-29 01:30:00', TIMESTAMP '2026-10-10 23:59:59')",
            ['id' => self::WEBHOOK],
        );
        $notification = $this->notification("TIMESTAMPTZ '2026-10-10 21:59:59+00'");
        $counts = $this->rowCounts();

        // 01:30 does not exist in London on this date; a session-zone conversion would shift it.
        $connection->executeStatement("SET LOCAL TIME ZONE 'Europe/London'");
        $this->migrate('up');
        $connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");

        self::assertSame('timestamp with time zone', $this->type('webhooks', 'created_at'));
        self::assertSame('timestamp with time zone', $this->type('notifications', 'created_at'));
        self::assertSame(
            ['created_at' => '2026-03-29 01:30:00+00', 'updated_at' => '2026-10-10 23:59:59+00'],
            $connection->fetchAssociative('SELECT created_at, updated_at FROM webhooks WHERE id = :id', ['id' => self::WEBHOOK]),
        );
        self::assertSame('2026-10-10 21:59:59+00', $connection->fetchOne('SELECT created_at FROM notifications WHERE id = :id', ['id' => $notification]));
        self::assertSame($counts, $this->rowCounts());
        $this->assertSchemaComparisonIsClean(['eq_device_profiles', 'recommendation_jobs', 'user_theme_moods', 'webhook_delivery_logs', 'webhooks', 'notifications', 'users']);
    }

    public function testMigratedColumnsKeepMicroseconds(): void
    {
        $connection = $this->manager->getConnection();
        $notification = $this->notification("TIMESTAMPTZ '2026-10-10 21:59:59.654321+00'");
        self::assertSame('2026-10-10 21:59:59.654321+00', $connection->fetchOne('SELECT created_at FROM notifications WHERE id = :id', ['id' => $notification]));

        // now() is the transaction start, with microseconds; a rounding default would differ from it.
        $defaulted = $this->notification(null);
        self::assertTrue($connection->fetchOne('SELECT created_at = now() FROM notifications WHERE id = :id', ['id' => $defaulted]));
        $connection->executeStatement(
            "INSERT INTO eq_device_profiles (id, user_id, name) VALUES (gen_random_uuid(), :user, 'Migration test')",
            ['user' => $this->createUser()->toString()],
        );
        self::assertSame(
            ['created_at' => true, 'updated_at' => true],
            $connection->fetchAssociative("SELECT created_at = now() AS created_at, updated_at = now() AS updated_at FROM eq_device_profiles WHERE name = 'Migration test'"),
        );
        self::assertSame(
            ['started_at' => null, 'completed_at' => null],
            $connection->fetchAllKeyValue(
                "SELECT a.attname, pg_get_expr(d.adbin, d.adrelid) FROM pg_attribute a
                 LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE a.attrelid = 'recommendation_jobs'::regclass AND a.attname IN ('started_at', 'completed_at') ORDER BY a.attname DESC",
            ),
        );
    }

    public function testDowngradeRestoresTheFormerTypesAndReadsThemInUtc(): void
    {
        $connection = $this->manager->getConnection();
        $connection->executeStatement(
            "INSERT INTO webhooks (id, url, encrypted_secret, created_at, updated_at)
             VALUES (:id, 'https://hooks.baander.app/migration', 'secret', TIMESTAMPTZ '2026-10-10 23:59:59.654321+00', TIMESTAMPTZ '2026-10-10 12:00:00.25+00')",
            ['id' => self::WEBHOOK],
        );
        $notification = $this->notification("TIMESTAMPTZ '2026-10-10 21:59:59.4+00'");

        $connection->executeStatement("SET LOCAL TIME ZONE 'Asia/Kathmandu'");
        $this->migrate('down');
        $connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");

        self::assertSame('timestamp(0) without time zone', $this->type('webhooks', 'created_at'));
        self::assertSame('timestamp(0) with time zone', $this->type('notifications', 'created_at'));
        // Rounding to whole seconds loses the fractions for good; 23:59:59.65 becomes the next day.
        self::assertSame(
            ['created_at' => '2026-10-11 00:00:00', 'updated_at' => '2026-10-10 12:00:00'],
            $connection->fetchAssociative('SELECT created_at, updated_at FROM webhooks WHERE id = :id', ['id' => self::WEBHOOK]),
        );
        self::assertSame('2026-10-10 21:59:59+00', $connection->fetchOne('SELECT created_at FROM notifications WHERE id = :id', ['id' => $notification]));
        self::assertSame(
            ['completed_at' => 'NULL::timestamp without time zone', 'eq_created_at' => 'now()', 'started_at' => 'NULL::timestamp without time zone'],
            $connection->fetchAllKeyValue(
                "SELECT CASE WHEN a.attrelid = 'eq_device_profiles'::regclass THEN 'eq_' ELSE '' END || a.attname, pg_get_expr(d.adbin, d.adrelid)
                 FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE (a.attrelid = 'recommendation_jobs'::regclass AND a.attname IN ('started_at', 'completed_at'))
                    OR (a.attrelid = 'eq_device_profiles'::regclass AND a.attname = 'created_at')
                 ORDER BY 1",
            ),
        );
    }

    private function migrate(string $direction): void
    {
        $connection = $this->manager->getConnection();
        require_once dirname(__DIR__, 2) . '/migrations/Version20261010180000.php';
        $migration = new Version20261010180000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function type(string $table, string $column): string
    {
        return (string) $this->manager->getConnection()->fetchOne(
            'SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = CAST(:table AS regclass) AND attname = :column',
            ['table' => $table, 'column' => $column],
        );
    }

    /** @param string|null $createdAt an SQL literal, or null for the column default */
    private function notification(?string $createdAt): string
    {
        $id = (string) $this->manager->getConnection()->fetchOne(sprintf(
            "INSERT INTO notifications (id, public_id, user_id, category, event_type, title, body%s)
             VALUES (gen_random_uuid(), :public_id, :user, 'system', 'migration_test', 'Migration', 'Body'%s) RETURNING id",
            $createdAt === null ? '' : ', created_at',
            $createdAt === null ? '' : ', ' . $createdAt,
        ), ['public_id' => bin2hex(random_bytes(8)), 'user' => $this->createUser()->toString()]);

        return $id;
    }

    /** @return array<string, int> every table's row count */
    private function rowCounts(): array
    {
        $connection = $this->manager->getConnection();
        $counts = [];
        foreach ($connection->fetchFirstColumn('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename') as $table) {
            $counts[$table] = (int) $connection->fetchOne('SELECT count(*) FROM ' . $connection->quoteSingleIdentifier((string) $table));
        }

        return $counts;
    }
}
