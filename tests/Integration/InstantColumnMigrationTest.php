<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\TestCase;

/** Instant columns retain microseconds and UTC defaults. */
final class InstantColumnMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
    }

    protected function setUp(): void
    {
        $this->setUpHarness();
        // Literal instants below are UTC, and so is their text form in the assertions.
        $this->manager->getConnection()->executeStatement("SET LOCAL TIME ZONE 'UTC'");
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
            "INSERT INTO eq_device_profiles (id, public_id, user_id, name) VALUES (gen_random_uuid(), :public_id, :user, 'Migration test')",
            ['user' => $this->createUser()->toString(), 'public_id' => (new PublicId())->toString()],
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
        $this->assertSchemaComparisonIsClean(['eq_device_profiles', 'recommendation_jobs', 'user_theme_moods', 'webhook_delivery_logs', 'webhooks', 'notifications', 'users']);
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
}
