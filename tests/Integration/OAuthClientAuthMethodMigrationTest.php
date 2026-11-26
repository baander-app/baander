<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Clients registered before the authentication method was stored get the method their
 * stored secret implies, and the backfill publishes no live update.
 */
final class OAuthClientAuthMethodMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261119130000';

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'oauth_auth_method_migration_' . bin2hex(random_bytes(8));
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

    public function testExistingClientsGetTheMethodTheirSecretImplies(): void
    {
        $this->migrate(self::precedesTheMigration(...));
        $secretClient = $this->insertFormerClient(hash('sha256', 'former-secret'));
        $publicClient = $this->insertFormerClient(null);
        $captured = $this->capturedChanges();

        $this->migrate(static fn (string $file): bool => basename($file, '.php') === self::MIGRATION);

        self::assertSame(
            [$secretClient => 'client_secret_post', $publicClient => 'none'],
            $this->connection->fetchAllKeyValue('SELECT id::text, token_endpoint_auth_method FROM oauth_clients ORDER BY confidential DESC'),
        );
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM oauth_clients WHERE jwks IS NOT NULL OR jwks_uri IS NOT NULL'));
        self::assertSame('NO', $this->connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'oauth_clients' AND column_name = 'token_endpoint_auth_method'",
        ));
        self::assertSame($captured, $this->capturedChanges(), 'The backfill published live updates.');
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

    private function insertFormerClient(?string $secretHash): string
    {
        $id = Uuid::generate()->toString();
        $this->connection->insert('oauth_clients', [
            'id' => $id,
            'public_id' => (new PublicId())->toString(),
            'name' => 'Former client',
            'redirect' => '["https://server.baander.app/callback"]',
            'confidential' => $secretHash !== null ? 'true' : 'false',
            'secret_hash' => $secretHash,
            'created_at' => '2026-11-01 12:00:00+00',
            'updated_at' => '2026-11-01 12:00:00+00',
        ]);

        return $id;
    }

    private function capturedChanges(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM query_change_inbox');
    }
}
