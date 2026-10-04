<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use DoctrineMigrations\Version20261004010000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebhookSigningMigrationTest extends TestCase
{
    private Connection $connection;
    private Connection $observer;
    private string $schemaName;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertNotFalse($url, 'Run with the disposable PostgreSQL functional runner.');
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->connection = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->schemaName = 'webhook_migration_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->executeStatement('SET search_path TO ' . $this->schemaName);
        $this->observer->executeStatement('SET search_path TO ' . $this->schemaName);
        $this->connection->executeStatement('CREATE TABLE webhooks (id TEXT PRIMARY KEY, secret_hash TEXT NOT NULL, signing_version SMALLINT NOT NULL DEFAULT 1, encrypted_secret TEXT DEFAULT NULL)');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schemaName . ' CASCADE');
        $this->connection->close();
        $this->observer->close();
    }

    public function testEncryptedSecretAndIdentitySurviveTheUpgrade(): void
    {
        $codec = new WebhookSecretCodec('test-app-secret');
        $ciphertext = $codec->encrypt('original-secret');
        $this->connection->insert('webhooks', ['id' => 'configured', 'secret_hash' => hash('sha256', 'original-secret'), 'signing_version' => 2, 'encrypted_secret' => $ciphertext]);

        $this->migrate();

        self::assertSame(['id' => 'configured', 'encrypted_secret' => $ciphertext], $this->observer->fetchAssociative('SELECT * FROM webhooks'));
        self::assertSame('original-secret', $codec->decrypt($this->observer->fetchOne('SELECT encrypted_secret FROM webhooks')));
        $columns = $this->observer->createSchemaManager()->listTableColumns('webhooks');
        self::assertTrue($columns['encrypted_secret']->getNotnull());
        self::assertArrayNotHasKey('secret_hash', $columns);
        self::assertArrayNotHasKey('signing_version', $columns);
        $this->expectException(Exception::class);
        $this->connection->insert('webhooks', ['id' => 'missing-secret']);
    }

    /** @return iterable<string, array{?string}> */
    public static function missingSecrets(): iterable
    {
        yield 'hash only' => [null];
        yield 'empty ciphertext' => [''];
    }

    #[DataProvider('missingSecrets')]
    public function testIrrecoverableSecretAbortsWithoutChangingDataOrSchema(?string $ciphertext): void
    {
        $row = ['id' => 'needs-rotation', 'secret_hash' => hash('sha256', 'original-secret'), 'signing_version' => 1, 'encrypted_secret' => $ciphertext];
        $this->connection->insert('webhooks', $row);
        try {
            $this->migrate();
            self::fail('A missing original secret must block the migration.');
        } catch (Exception $exception) {
            self::assertStringContainsString('require rotation before upgrade', $exception->getMessage());
        }
        $stored = $this->observer->fetchAssociative('SELECT * FROM webhooks');
        self::assertSame($row['id'], $stored['id']);
        self::assertSame($row['secret_hash'], $stored['secret_hash']);
        self::assertSame(1, (int) $stored['signing_version']);
        self::assertSame($ciphertext, $stored['encrypted_secret']);
        self::assertFalse($this->observer->createSchemaManager()->listTableColumns('webhooks')['encrypted_secret']->getNotnull());
    }

    private function migrate(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261004010000.php';
        $migration = new Version20261004010000($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }
}
