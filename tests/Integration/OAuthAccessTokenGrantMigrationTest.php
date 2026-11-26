<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stored access tokens do not say how their chain began, so the migration that records the
 * sign-in grant deletes them, with their refresh tokens and metadata, and keeps accounts and
 * clients. Afterwards every access token names one of the four grants.
 */
final class OAuthAccessTokenGrantMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261122160000';
    private const array TIMES = ['created_at' => '2026-11-22 12:00:00+00', 'updated_at' => '2026-11-22 12:00:00+00'];

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'oauth_access_token_grant_migration_' . bin2hex(random_bytes(8));
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

    public function testStoredSessionsEndAndEveryLaterTokenNamesAKnownGrant(): void
    {
        $this->migrate(self::precedesTheMigration(...));
        $user = $this->insertUser();
        $client = $this->insertClient();
        $access = $this->insertAccessToken($client, $user);
        $refreshId = Uuid::generate()->toString();
        $refreshSecret = bin2hex(random_bytes(40));
        $this->connection->insert('oauth_refresh_tokens', [
            'id' => $refreshId,
            'token_id' => hash('sha256', $refreshSecret),
            'access_token_id' => $access,
            'credential_epoch' => 0,
            'chain_expires_at' => '2027-01-01 00:00:00+00',
            ...self::TIMES,
        ]);
        $this->connection->insert('oauth_token_metadata', ['id' => Uuid::generate()->toString(), 'token_id' => $access, ...self::TIMES]);

        $this->migrate(static fn (string $file): bool => basename($file, '.php') === self::MIGRATION);

        foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_token_metadata'] as $table) {
            self::assertSame(0, (int) $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s', $table)), $table);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM users WHERE id = ?', [$user]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM oauth_clients WHERE id = ?', [$client]));
        self::assertSame(
            ['is_nullable' => 'NO', 'column_default' => null],
            $this->connection->fetchAssociative(
                "SELECT is_nullable, column_default FROM information_schema.columns
                  WHERE table_schema = ? AND table_name = 'oauth_access_tokens' AND column_name = 'grant_type'",
                [$this->schema],
            ),
        );

        foreach (['password', 'passkey', 'authorization_code', 'device_code'] as $grant) {
            $this->insertAccessToken($client, $user, $grant);
        }
        // DBAL 4.4 has no CHECK violation exception class; 23514 is check_violation.
        $this->assertCheckViolation(fn () => $this->insertAccessToken($client, $user, 'client_credentials'));
    }

    /** Runs the selected migrations in one transaction; extensions are provisioned in the public schema. */
    private function migrate(\Closure $selected): void
    {
        $files = array_values(array_filter(glob(dirname(__DIR__, 2) . '/migrations/Version*.php') ?: [], $selected));
        sort($files, SORT_STRING);
        self::assertNotSame([], $files);
        $this->connection->transactional(function () use ($files): void {
            foreach ($files as $file) {
                require_once $file;
                $class = 'DoctrineMigrations\\' . basename($file, '.php');
                $migration = new $class($this->connection, new NullLogger());
                self::assertInstanceOf(AbstractMigration::class, $migration);
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) {
                    $sql = $query->getStatement();
                    if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($sql)) === 1) {
                        continue;
                    }
                    $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            }
        });
    }

    private static function precedesTheMigration(string $file): bool
    {
        return strcmp(basename($file, '.php'), self::MIGRATION) < 0;
    }

    private function insertUser(): string
    {
        $id = Uuid::generate()->toString();
        $this->connection->executeStatement(
            "INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, ?, 'Grant migration', 'grant-migration@baander.app', 'hash', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString()],
        );

        return $id;
    }

    private function insertClient(): string
    {
        $id = Uuid::generate()->toString();
        $this->connection->insert('oauth_clients', [
            'id' => $id,
            'public_id' => (new PublicId())->toString(),
            'name' => 'Grant migration player',
            'redirect' => '["https://player.baander.app/callback"]',
            ...self::TIMES,
        ]);

        return $id;
    }

    /** Inserts an access token, with the grant column once the migration added it. */
    private function insertAccessToken(string $client, string $user, ?string $grant = null): string
    {
        $id = Uuid::generate()->toString();
        $row = [
            'id' => $id,
            'token_id' => bin2hex(random_bytes(40)),
            'client_id' => $client,
            'user_id' => $user,
            'credential_epoch' => 0,
            ...self::TIMES,
        ];
        if ($grant !== null) {
            $row['grant_type'] = $grant;
        }
        $this->connection->insert('oauth_access_tokens', $row);

        return $id;
    }

    /** Runs $write in a transaction of its own and expects PostgreSQL to reject it as a check violation. */
    private function assertCheckViolation(\Closure $write): void
    {
        $this->connection->beginTransaction();
        try {
            $write();
            self::fail('Expected SQLSTATE 23514');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        } finally {
            $this->connection->rollBack();
        }
    }
}
