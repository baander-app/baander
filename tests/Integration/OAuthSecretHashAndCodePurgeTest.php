<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007100000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Client secret digests, device code decisions and the expired-code purge (Version20261007100000),
 * on the fully migrated disposable PostgreSQL inside one rolled-back transaction.
 */
final class OAuthSecretHashAndCodePurgeTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testClientChecksTieTheSecretDigestToConfidentiality(): void
    {
        self::assertSame([
            'chk_oauth_clients_confidential_secret_hash' => 'CHECK ((confidential = (secret_hash IS NOT NULL)))',
            'chk_oauth_clients_secret_hash_format' => "CHECK ((secret_hash ~ '^[0-9a-f]{64}$'::text))",
        ], $this->checks('oauth_clients'));

        $digest = hash('sha256', 'constraint-secret');
        $this->insertClient(confidential: true, secretHash: $digest);
        $this->insertClient(confidential: false, secretHash: null);

        // DBAL 4.4 has no CHECK violation exception class; 23514 is check_violation.
        $this->assertRejected(fn () => $this->insertClient(confidential: true, secretHash: null));
        $this->assertRejected(fn () => $this->insertClient(confidential: false, secretHash: $digest));
        $this->assertRejected(fn () => $this->insertClient(confidential: true, secretHash: strtoupper($digest)));
        $this->assertRejected(fn () => $this->insertClient(confidential: true, secretHash: substr($digest, 1)));
        $this->assertRejected(fn () => $this->insertClient(confidential: true, secretHash: 'plain-text-secret'));
    }

    public function testDeviceCodeChecksRequireOneDecisionAndAnApprovingUser(): void
    {
        self::assertSame([
            'chk_oauth_device_codes_approved_user_id' => 'CHECK (((NOT approved) OR (user_id IS NOT NULL)))',
            'chk_oauth_device_codes_consumed_approved' => 'CHECK (((consumed_at IS NULL) OR approved))',
            'chk_oauth_device_codes_single_decision' => 'CHECK ((NOT (approved AND denied)))',
        ], $this->checks('oauth_device_codes'));

        $client = $this->insertClient();
        $user = $this->createUser();
        $this->insertDeviceCode($client);
        $this->insertDeviceCode($client, ['denied' => 'true']);
        $this->insertDeviceCode($client, ['approved' => 'true', 'user_id' => $user->toString(), 'consumed_at' => '2026-10-07 12:00:00+00']);

        $this->assertRejected(fn () => $this->insertDeviceCode($client, ['approved' => 'true']));
        $this->assertRejected(fn () => $this->insertDeviceCode($client, ['approved' => 'true', 'denied' => 'true', 'user_id' => $user->toString()]));
        $this->assertRejected(fn () => $this->insertDeviceCode($client, ['consumed_at' => '2026-10-07 12:00:00+00']));
    }

    public function testMigrationHashesSecretsRevokesUnusableClientsAndDropsInvalidDeviceCodes(): void
    {
        $clientChecks = $this->checks('oauth_clients');
        $deviceChecks = $this->checks('oauth_device_codes');
        $this->runMigration('down');
        self::assertSame([], $this->checks('oauth_clients'));
        self::assertSame([], $this->checks('oauth_device_codes'));
        self::assertFalse($this->scheduledPurge());

        $confidential = $this->insertLegacyClient(confidential: true, secret: 'plain-secret');
        $unicode = $this->insertLegacyClient(confidential: true, secret: 'sécret-🎧');
        $public = $this->insertLegacyClient(confidential: false, secret: 'stray-secret');
        $missing = $this->insertLegacyClient(confidential: true, secret: null);
        $empty = $this->insertLegacyClient(confidential: true, secret: '');
        $user = $this->createUser();
        [$accessToken, $refreshToken] = $this->insertTokenPair($missing, $user);

        $pending = $this->insertDeviceCode($public);
        $orphanApproval = $this->insertDeviceCode($public, ['approved' => 'true']);
        $doubleDecision = $this->insertDeviceCode($public, ['approved' => 'true', 'denied' => 'true', 'user_id' => $user->toString()]);
        $unapprovedUse = $this->insertDeviceCode($public, ['consumed_at' => '2026-10-07 12:00:00+00']);

        $this->runMigration('up');

        self::assertSame($clientChecks, $this->checks('oauth_clients'));
        self::assertSame($deviceChecks, $this->checks('oauth_device_codes'));
        self::assertSame(['secret_hash' => hash('sha256', 'plain-secret'), 'revoked' => false], $this->client($confidential));
        self::assertSame(['secret_hash' => hash('sha256', 'sécret-🎧'), 'revoked' => false], $this->client($unicode));
        self::assertSame(['secret_hash' => null, 'revoked' => false], $this->client($public));
        foreach ([$missing, $empty] as $unusable) {
            $row = $this->client($unusable);
            self::assertTrue($row['revoked']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['secret_hash']);
            self::assertNotSame(hash('sha256', ''), $row['secret_hash']);
        }
        self::assertNotSame($this->client($missing)['secret_hash'], $this->client($empty)['secret_hash']);
        $connection = $this->manager->getConnection();
        self::assertTrue((bool) $connection->fetchOne('SELECT revoked FROM oauth_access_tokens WHERE id = ?', [$accessToken->toString()]));
        self::assertTrue((bool) $connection->fetchOne('SELECT revoked FROM oauth_refresh_tokens WHERE id = ?', [$refreshToken->toString()]));

        self::assertSame(
            [$pending->toString()],
            array_values(array_intersect(
                $connection->fetchFirstColumn('SELECT id::text FROM oauth_device_codes'),
                [$pending->toString(), $orphanApproval->toString(), $doubleDecision->toString(), $unapprovedUse->toString()],
            )),
        );
        self::assertNotFalse($this->scheduledPurge());
    }

    public function testDailyPurgeIsScheduledForTheRegisteredCommand(): void
    {
        self::loadMigration();
        $job = $this->scheduledPurge();
        self::assertIsArray($job);
        self::assertSame([
            'name' => 'Purge expired OAuth codes',
            'expression' => '30 3 * * *',
            'job_type' => 'messenger',
            'command' => Version20261007100000::PURGE_COMMAND,
            'status' => 'active',
            'parameters' => '[]',
            'evaluated_through' => null,
        ], array_intersect_key($job, array_flip(['name', 'expression', 'job_type', 'command', 'status', 'parameters', 'evaluated_through'])));
        self::assertTrue((bool) $this->manager->getConnection()->fetchOne(
            "SELECT next_run_at > clock_timestamp() - INTERVAL '1 day' AND to_char(next_run_at AT TIME ZONE 'UTC', 'HH24:MI:SS') = '03:30:00' FROM scheduled_jobs WHERE id = ?",
            [Version20261007100000::PURGE_JOB_ID],
        ));

        $registry = $this->kernel->getContainer()->get('test.service_container')->get(SchedulerRegistry::class);
        self::assertInstanceOf(SchedulerRegistry::class, $registry);
        self::assertTrue($registry->isMessengerCommandAllowed(Version20261007100000::PURGE_COMMAND));
    }

    public function testPurgeDeletesOnlyCodesExpiredBeforeTheCutoff(): void
    {
        $client = $this->insertClient();
        $user = $this->createUser();
        $cutoff = new DateTimeImmutable('2026-10-07T12:00:00.500000+00:00');

        $authCodes = [
            'expired' => $this->insertAuthCode($client, $user, '2026-10-07 11:59:59+00'),
            'expired and used' => $this->insertAuthCode($client, $user, '2026-10-06 12:00:00+00', revoked: true),
            'half a second after the cutoff' => $this->insertAuthCode($client, $user, '2026-10-07 12:00:01+00'),
            'unexpired' => $this->insertAuthCode($client, $user, '2026-10-07 13:00:00+00'),
            'no expiry' => $this->insertAuthCode($client, $user, null),
        ];
        $deviceCodes = [
            'expired pending' => $this->insertDeviceCode($client, ['expires_at' => '2026-10-07 11:00:00+00']),
            'expired denied' => $this->insertDeviceCode($client, ['expires_at' => '2026-10-07 11:59:59+00', 'denied' => 'true']),
            'expired consumed' => $this->insertDeviceCode($client, ['expires_at' => '2026-10-07 11:59:59+00', 'approved' => 'true', 'user_id' => $user->toString(), 'consumed_at' => '2026-10-07 11:50:00+00']),
            'unexpired' => $this->insertDeviceCode($client, ['expires_at' => '2026-10-07 12:00:01+00']),
            'no expiry' => $this->insertDeviceCode($client, ['expires_at' => null]),
        ];

        $container = $this->kernel->getContainer()->get('test.service_container');
        $authCodeRepository = $container->get(AuthCodeRepositoryInterface::class);
        $deviceCodeRepository = $container->get(DeviceCodeRepositoryInterface::class);
        self::assertInstanceOf(AuthCodeRepositoryInterface::class, $authCodeRepository);
        self::assertInstanceOf(DeviceCodeRepositoryInterface::class, $deviceCodeRepository);

        // Other rows of the shared database may also be expired, so only the fixture rows are compared.
        self::assertGreaterThanOrEqual(2, $authCodeRepository->deleteExpiredBefore($cutoff));
        self::assertGreaterThanOrEqual(3, $deviceCodeRepository->deleteExpiredBefore($cutoff));

        self::assertSame(['half a second after the cutoff', 'unexpired', 'no expiry'], $this->remaining('oauth_auth_codes', $authCodes));
        self::assertSame(['unexpired', 'no expiry'], $this->remaining('oauth_device_codes', $deviceCodes));
    }

    /**
     * @param array<string, Uuid> $rows label => id
     *
     * @return list<string> The labels of rows still present, in fixture order
     */
    private function remaining(string $table, array $rows): array
    {
        $present = $this->manager->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM ' . $table . ' WHERE id IN (?)',
            [array_map(static fn (Uuid $id): string => $id->toString(), array_values($rows))],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_keys(array_filter($rows, static fn (Uuid $id): bool => in_array($id->toString(), $present, true)));
    }

    /** Runs $write in a savepoint and expects PostgreSQL to reject it as a CHECK violation. */
    private function assertRejected(\Closure $write): void
    {
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        try {
            $write();
            self::fail('Expected SQLSTATE 23514');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        } finally {
            $connection->rollBack();
        }
    }

    private function insertClient(bool $confidential = false, ?string $secretHash = null): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert('oauth_clients', [
            'id' => $id->toString(),
            'public_id' => (new PublicId())->toString(),
            'name' => 'Constraint client',
            'redirect' => '["https://app.baander.app/callback"]',
            'confidential' => $confidential ? 'true' : 'false',
            'secret_hash' => $secretHash,
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    /** A client row in the shape before Version20261007100000, with its plain-text secret column. */
    private function insertLegacyClient(bool $confidential, ?string $secret): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert('oauth_clients', [
            'id' => $id->toString(),
            'public_id' => (new PublicId())->toString(),
            'name' => 'Legacy client',
            'redirect' => '["https://app.baander.app/callback"]',
            'confidential' => $confidential ? 'true' : 'false',
            'secret' => $secret,
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    /** @return array{Uuid, Uuid} access token ID, refresh token ID */
    private function insertTokenPair(Uuid $client, Uuid $user): array
    {
        $connection = $this->manager->getConnection();
        $access = Uuid::generate();
        $refresh = Uuid::generate();
        $connection->insert('oauth_access_tokens', [
            'id' => $access->toString(),
            'token_id' => bin2hex(random_bytes(16)),
            'user_id' => $user->toString(),
            'client_id' => $client->toString(),
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);
        $connection->insert('oauth_refresh_tokens', [
            'id' => $refresh->toString(),
            'token_id' => bin2hex(random_bytes(16)),
            'access_token_id' => $access->toString(),
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return [$access, $refresh];
    }

    private function insertAuthCode(Uuid $client, Uuid $user, ?string $expiresAt, bool $revoked = false): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert('oauth_auth_codes', [
            'id' => $id->toString(),
            'code_id' => bin2hex(random_bytes(16)),
            'client_id' => $client->toString(),
            'user_id' => $user->toString(),
            'redirect_uri' => 'https://app.baander.app/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'revoked' => $revoked ? 'true' : 'false',
            'expires_at' => $expiresAt,
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ]);

        return $id;
    }

    /** @param array<string, string|null> $overrides */
    private function insertDeviceCode(Uuid $client, array $overrides = []): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert('oauth_device_codes', [
            'id' => $id->toString(),
            'device_code' => bin2hex(random_bytes(16)),
            'user_code' => strtoupper(bin2hex(random_bytes(6))),
            'client_id' => $client->toString(),
            'verification_uri' => 'https://app.baander.app/device',
            'expires_at' => '2026-10-07 12:15:00+00',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
            ...$overrides,
        ]);

        return $id;
    }

    /** @return array{secret_hash: string|null, revoked: bool} */
    private function client(Uuid $id): array
    {
        $row = $this->manager->getConnection()->fetchAssociative('SELECT secret_hash, revoked FROM oauth_clients WHERE id = ?', [$id->toString()]);
        self::assertIsArray($row);

        return ['secret_hash' => $row['secret_hash'] === null ? null : (string) $row['secret_hash'], 'revoked' => (bool) $row['revoked']];
    }

    /** @return array<string, mixed>|false */
    private function scheduledPurge(): array|false
    {
        return $this->manager->getConnection()->fetchAssociative(
            'SELECT name, expression, job_type, command, status, parameters::text AS parameters, evaluated_through FROM scheduled_jobs WHERE id = ?',
            [Version20261007100000::PURGE_JOB_ID],
        );
    }

    /** Migration classes are not autoloaded. */
    private static function loadMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261007100000.php';
    }

    private function runMigration(string $direction): void
    {
        self::loadMigration();
        $connection = $this->manager->getConnection();
        $migration = new Version20261007100000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, string> CHECK constraint name => definition */
    private function checks(string $table): array
    {
        return $this->manager->getConnection()->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
              WHERE contype = 'c' AND conrelid = CAST(:table AS regclass) ORDER BY 1",
            ['table' => $table],
        );
    }
}
