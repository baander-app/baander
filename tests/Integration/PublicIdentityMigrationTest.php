<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

final class PublicIdentityMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const array TABLES = [
        'libraries', 'genres', 'passkeys', 'login_blocks', 'devices',
        'listening_sessions', 'webhooks', 'scheduled_jobs', 'recommendations',
        'eq_device_profiles', 'radio_sources', 'radio_stations',
        'country_subscriptions', 'scheduler_occurrences',
    ];

    public function testPublicIdsAreExplicitAndUnique(): void
    {
        $connection = $this->manager->getConnection();
        $publicId = (new PublicId())->toString();
        $row = [
            'id' => Uuid::v7()->toString(),
            'public_id' => $publicId,
            'name' => 'Public identity constraint',
            'slug' => 'identity-' . bin2hex(random_bytes(8)),
            'path' => '/media/identity-test',
            'type' => 'music',
            'created_at' => '2026-10-17 00:00:00+00',
            'updated_at' => '2026-10-17 00:00:00+00',
        ];
        $connection->insert('libraries', $row);
        $another = array_replace($row, ['id' => Uuid::v7()->toString(), 'slug' => 'identity-' . bin2hex(random_bytes(8))]);
        $this->assertSqlState('23505', fn () => $connection->insert('libraries', $another));
        unset($another['public_id']);
        $this->assertSqlState('23502', fn () => $connection->insert('libraries', $another));
        $another['public_id'] = null;
        $this->assertSqlState('23502', fn () => $connection->insert('libraries', $another));
        self::assertSame($publicId, $connection->fetchOne('SELECT public_id FROM libraries WHERE id = ?', [$row['id']]));
    }

    public function testMigratedCatalogHasTextPublicIdsWithoutDefaultsAndNamedUniqueConstraints(): void
    {
        $connection = $this->manager->getConnection();
        foreach (self::TABLES as $table) {
            self::assertSame([
                'data_type' => 'text',
                'is_nullable' => 'NO',
                'column_default' => null,
            ], $connection->fetchAssociative(
                "SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'public_id'",
                [$table],
            ), $table);
            self::assertSame('UNIQUE (public_id)', $connection->fetchOne(
                'SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = CAST(? AS regclass) AND conname = ?',
                [$table, 'uniq_' . $table . '_public_id'],
            ), $table);
        }
        self::assertSame(['data_type' => 'text', 'is_nullable' => 'NO', 'column_default' => null], $connection->fetchAssociative(
            "SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'scheduler_occurrences' AND column_name = 'job_public_id'",
        ));
    }

    /** @param callable(): mixed $write */
    private function assertSqlState(string $expected, callable $write): void
    {
        $connection = $this->manager->getConnection();
        $connection->executeStatement('SAVEPOINT public_identity_check');
        try {
            $write();
            self::fail('The write must fail with SQLSTATE ' . $expected);
        } catch (DriverException $exception) {
            self::assertSame($expected, $exception->getSQLState());
        } finally {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT public_identity_check');
            $connection->executeStatement('RELEASE SAVEPOINT public_identity_check');
        }
    }
}
