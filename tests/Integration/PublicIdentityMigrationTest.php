<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261015120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PublicIdentityMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const array TABLES = [
        'libraries', 'genres', 'passkeys', 'login_blocks', 'devices',
        'listening_sessions', 'webhooks', 'scheduled_jobs', 'recommendations',
        'eq_device_profiles', 'radio_sources', 'radio_stations',
        'country_subscriptions', 'scheduler_occurrences',
    ];

    public function testPopulatedUpgradePreservesExistingDataAndInvalidatesOldPasskeys(): void
    {
        $connection = $this->legacySchema();
        $before = [];
        $ownerId = Uuid::v7()->toString();
        foreach (self::TABLES as $table) {
            for ($index = 0; $index < 3; ++$index) {
                $row = [
                    'id' => Uuid::v7()->toString(),
                    'revision' => Uuid::v7()->toString(),
                    'owner_id' => $ownerId,
                    'credential_data' => json_encode([
                        'credentialId' => 'credential-' . $index,
                        'userHandle' => $ownerId,
                        'clientDeviceToken' => Uuid::v7()->toString(),
                        'requestKey' => Uuid::v7()->toString(),
                        'payload' => ['preserve' => true, 'position' => 0.125],
                    ], JSON_THROW_ON_ERROR),
                ];
                if ($table === 'scheduler_occurrences') {
                    $row['job_id'] = $ownerId;
                }
                $connection->insert($table, $row);
            }
            $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }
        $jobId = $connection->fetchOne('SELECT id FROM scheduled_jobs ORDER BY id LIMIT 1');
        $connection->executeStatement('UPDATE scheduler_occurrences SET owner_id = ?', [$jobId]);
        $connection->executeStatement('ALTER TABLE scheduler_occurrences ADD CONSTRAINT occurrence_job FOREIGN KEY (owner_id) REFERENCES scheduled_jobs(id)');
        $before['scheduler_occurrences'] = $connection->fetchAllAssociative('SELECT * FROM scheduler_occurrences ORDER BY id');

        $this->migrate();

        foreach (self::TABLES as $table) {
            $after = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
            if ($table === 'passkeys') {
                self::assertSame([], $after);
                continue;
            }
            self::assertCount(3, $after, $table);
            $publicIds = [];
            foreach ($after as &$row) {
                $publicId = PublicId::fromString($row['public_id']);
                self::assertNotSame($row['id'], $publicId->toString(), $table);
                $publicIds[] = $publicId->toString();
                unset($row['public_id'], $row['job_public_id']);
            }
            unset($row);
            self::assertCount(3, array_unique($publicIds), $table);
            self::assertSame($before[$table], $after, $table);
        }
        self::assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM scheduler_occurrences o JOIN scheduled_jobs j ON j.id = o.owner_id'));
    }

    public function testPasskeyReEnrollmentPreservesAccountsPasswordsAndOtherCredentials(): void
    {
        $connection = $this->legacySchema();
        $connection->executeStatement('CREATE TABLE users (id UUID PRIMARY KEY, public_id TEXT NOT NULL, password TEXT NOT NULL, roles JSONB NOT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_access_tokens (id UUID PRIMARY KEY, user_id UUID NOT NULL REFERENCES users(id), token_id TEXT NOT NULL)');
        $connection->executeStatement('ALTER TABLE passkeys ADD COLUMN user_id UUID REFERENCES users(id) ON DELETE CASCADE, ADD COLUMN credential_id TEXT UNIQUE, ADD COLUMN data JSONB');
        $users = [];
        foreach (['with-passkey', 'without-passkey'] as $name) {
            $users[$name] = ['id' => Uuid::v7()->toString(), 'public_id' => (new PublicId())->toString(), 'password' => 'preserved-password-hash-' . $name, 'roles' => '["ROLE_USER"]'];
            $connection->insert('users', $users[$name]);
            $connection->insert('oauth_access_tokens', ['id' => Uuid::v7()->toString(), 'user_id' => $users[$name]['id'], 'token_id' => 'preserved-token-' . $name]);
        }
        $connection->insert('passkeys', [
            'id' => Uuid::v7()->toString(),
            'user_id' => $users['with-passkey']['id'],
            'credential_id' => 'old-authenticator-credential',
            'data' => json_encode(['userHandle' => $users['with-passkey']['id']], JSON_THROW_ON_ERROR),
        ]);
        $accounts = $connection->fetchAllAssociative('SELECT * FROM users ORDER BY id');
        $tokens = $connection->fetchAllAssociative('SELECT * FROM oauth_access_tokens ORDER BY id');

        $this->migrate();

        self::assertSame([], $connection->fetchAllAssociative('SELECT * FROM passkeys'));
        self::assertSame($accounts, $connection->fetchAllAssociative('SELECT * FROM users ORDER BY id'));
        self::assertSame($tokens, $connection->fetchAllAssociative('SELECT * FROM oauth_access_tokens ORDER BY id'));
        $newPublicId = (new PublicId())->toString();
        $connection->insert('passkeys', [
            'id' => Uuid::v7()->toString(),
            'public_id' => $newPublicId,
            'user_id' => $users['with-passkey']['id'],
            'credential_id' => 'new-authenticator-credential',
            'data' => json_encode(['userHandle' => $users['with-passkey']['public_id']], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(['public_id' => $newPublicId, 'user_id' => $users['with-passkey']['id'], 'user_handle' => $users['with-passkey']['public_id']], $connection->fetchAssociative("SELECT public_id, user_id, data->>'userHandle' AS user_handle FROM passkeys"));
        self::assertSame($accounts, $connection->fetchAllAssociative('SELECT * FROM users ORDER BY id'));
    }

    public function testEmptyUpgradeRequiresExplicitPublicIdsAndEnforcesUniqueness(): void
    {
        $connection = $this->legacySchema();
        $this->migrate();

        foreach (self::TABLES as $table) {
            $publicId = (new PublicId())->toString();
            $references = $table === 'scheduler_occurrences' ? ['job_id' => Uuid::v7()->toString(), 'job_public_id' => (new PublicId())->toString()] : [];
            $connection->insert($table, ['id' => Uuid::v7()->toString(), 'public_id' => $publicId] + $references);
            $this->assertSqlState('23505', fn () => $connection->insert($table, ['id' => Uuid::v7()->toString(), 'public_id' => $publicId] + $references));
            $this->assertSqlState('23502', fn () => $connection->insert($table, ['id' => Uuid::v7()->toString()] + $references));
            $this->assertSqlState('23502', fn () => $connection->insert($table, ['id' => Uuid::v7()->toString(), 'public_id' => null] + $references));
            self::assertSame($publicId, $connection->fetchOne('SELECT public_id FROM ' . $table));
        }
    }

    public function testOccurrenceJobSnapshotsKeepLiveAndDeletedJobIdentitiesIndependentOfReceiptIds(): void
    {
        $connection = $this->legacySchema();
        $liveJob = Uuid::v7()->toString();
        $deletedJob = Uuid::v7()->toString();
        $otherDeletedJob = Uuid::v7()->toString();
        $connection->insert('scheduled_jobs', ['id' => $liveJob]);
        foreach ([$liveJob, $liveJob, $deletedJob, $deletedJob, $otherDeletedJob] as $jobId) {
            $connection->insert('scheduler_occurrences', ['id' => Uuid::v7()->toString(), 'job_id' => $jobId]);
        }
        $before = $connection->fetchAllAssociative('SELECT id, job_id FROM scheduler_occurrences ORDER BY id');

        $this->migrate();

        self::assertSame($before, $connection->fetchAllAssociative('SELECT id, job_id FROM scheduler_occurrences ORDER BY id'));
        $jobPublicIds = [];
        foreach ([$liveJob, $deletedJob, $otherDeletedJob] as $jobId) {
            $publicIds = $connection->fetchFirstColumn('SELECT DISTINCT job_public_id FROM scheduler_occurrences WHERE job_id = ?', [$jobId]);
            self::assertCount(1, $publicIds);
            $jobPublicIds[] = PublicId::fromString($publicIds[0])->toString();
        }
        self::assertCount(3, array_unique($jobPublicIds));
        self::assertSame($connection->fetchOne('SELECT public_id FROM scheduled_jobs WHERE id = ?', [$liveJob]), $jobPublicIds[0]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE public_id = job_public_id'));
        $connection->executeStatement('DELETE FROM scheduled_jobs WHERE id = ?', [$liveJob]);
        self::assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE job_public_id = ?', [$jobPublicIds[0]]));
        $this->assertSqlState('23502', fn () => $connection->insert('scheduler_occurrences', [
            'id' => Uuid::v7()->toString(), 'job_id' => $liveJob, 'public_id' => (new PublicId())->toString(),
        ]));
    }

    public function testMissingActiveDevicesAreRecoveredPerOwnerWithoutReplacingRegisteredDevicesOrTokens(): void
    {
        $connection = $this->legacySchema();
        $registeredOwner = Uuid::v7()->toString();
        $recoveredOwner = Uuid::v7()->toString();
        $otherOwner = Uuid::v7()->toString();
        $registeredToken = Uuid::v7()->toString();
        $sharedToken = Uuid::v7()->toString();
        $registeredId = Uuid::v7()->toString();
        $connection->insert('devices', [
            'id' => $registeredId,
            'user_id' => $registeredOwner,
            'device_id' => $registeredToken,
            'name' => 'Registered device',
            'created_at' => '2026-10-01 12:00:00+00',
        ]);
        foreach ([[$registeredOwner, $registeredToken], [$recoveredOwner, $sharedToken], [$otherOwner, $sharedToken], [Uuid::v7()->toString(), null]] as [$userId, $token]) {
            $connection->insert('listening_sessions', ['id' => Uuid::v7()->toString(), 'user_id' => $userId, 'active_device_id' => $token]);
        }
        $before = $connection->fetchAllAssociative('SELECT id, user_id, active_device_id FROM listening_sessions ORDER BY id');

        $this->migrate();

        self::assertSame($before, $connection->fetchAllAssociative('SELECT id, user_id, active_device_id FROM listening_sessions ORDER BY id'));
        self::assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM devices'));
        self::assertSame(['id' => $registeredId, 'name' => 'Registered device', 'created_at' => '2026-10-01 12:00:00+00'], $connection->fetchAssociative('SELECT id, name, created_at FROM devices WHERE id = ?', [$registeredId]));
        $recovered = $connection->fetchAllAssociative('SELECT * FROM devices WHERE device_id = ? ORDER BY user_id', [$sharedToken]);
        self::assertCount(2, $recovered);
        $owners = [];
        $publicIds = [];
        foreach ($recovered as $device) {
            $owners[] = $device['user_id'];
            $publicIds[] = PublicId::fromString($device['public_id'])->toString();
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $device['id']);
            self::assertNotNull($device['created_at']);
            self::assertNull($device['name']);
        }
        self::assertEqualsCanonicalizing([$recoveredOwner, $otherOwner], $owners);
        self::assertCount(2, array_unique($publicIds));
        self::assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM listening_sessions s JOIN devices d ON d.user_id = s.user_id AND d.device_id = s.active_device_id'));
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

    public function testFailureRollsBackEarlierTablesAndTheirBackfills(): void
    {
        $connection = $this->legacySchema();
        $connection->insert('libraries', ['id' => Uuid::v7()->toString(), 'credential_data' => 'retained']);
        $connection->insert('passkeys', ['id' => Uuid::v7()->toString(), 'credential_data' => 'retained-until-commit']);
        $before = $connection->fetchAllAssociative('SELECT * FROM libraries');
        $passkeysBefore = $connection->fetchAllAssociative('SELECT * FROM passkeys');
        $connection->executeStatement('ALTER TABLE scheduler_occurrences ADD COLUMN public_id TEXT');
        $this->assertSqlState('42701', fn () => $this->migrate());

        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM libraries'));
        self::assertSame($passkeysBefore, $connection->fetchAllAssociative('SELECT * FROM passkeys'));
        self::assertFalse($connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'libraries' AND column_name = 'public_id')",
        ));
    }

    private function legacySchema(): Connection
    {
        $connection = $this->manager->getConnection();
        $name = 'public_identity_' . bin2hex(random_bytes(8));
        $connection->executeStatement('CREATE SCHEMA ' . $name);
        // pgcrypto remains in public; all target tables are shadowed here.
        $connection->executeStatement('SET LOCAL search_path TO ' . $name . ', public');
        foreach (self::TABLES as $table) {
            $connection->executeStatement('CREATE TABLE ' . $table . ' (id UUID PRIMARY KEY, revision UUID, owner_id UUID, credential_data TEXT)');
        }
        $connection->executeStatement('ALTER TABLE devices ADD COLUMN user_id UUID, ADD COLUMN device_id UUID, ADD COLUMN name TEXT, ADD COLUMN created_at TIMESTAMPTZ, ADD CONSTRAINT device_owner_token UNIQUE (user_id, device_id)');
        $connection->executeStatement('ALTER TABLE listening_sessions ADD COLUMN user_id UUID, ADD COLUMN active_device_id UUID');
        $connection->executeStatement('ALTER TABLE scheduler_occurrences ADD COLUMN job_id UUID NOT NULL');
        $connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");

        return $connection;
    }

    private function migrate(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261015120000.php';
        $connection = $this->manager->getConnection();
        $migration = new Version20261015120000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
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
