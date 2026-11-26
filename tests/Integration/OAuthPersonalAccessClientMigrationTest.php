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
 * Personal access clients created with the first-party flag lose it, and the schema then
 * refuses a personal access client flagged first-party. The first-party client keeps its flag.
 */
final class OAuthPersonalAccessClientMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261122150000';

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'oauth_personal_access_migration_' . bin2hex(random_bytes(8));
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

    public function testPersonalAccessClientsLoseTheFirstPartyFlagAndCannotRegainIt(): void
    {
        $this->migrate(self::precedesTheMigration(...));
        $personalAccess = $this->insertClient(personalAccess: true, firstParty: true);
        $firstParty = $this->insertClient(personalAccess: false, firstParty: true);

        $this->migrate(static fn (string $file): bool => basename($file, '.php') === self::MIGRATION);

        self::assertSame(
            [$personalAccess => 'false', $firstParty => 'true'],
            $this->connection->fetchAllKeyValue('SELECT id::text, first_party::text FROM oauth_clients ORDER BY personal_access_client DESC'),
        );

        // DBAL 4.4 has no CHECK violation exception class; 23514 is check_violation.
        $this->assertCheckViolation(fn () => $this->insertClient(personalAccess: true, firstParty: true));
        $this->assertCheckViolation(fn () => $this->connection->executeStatement(
            'UPDATE oauth_clients SET first_party = TRUE WHERE id = ?',
            [$personalAccess],
        ));
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

    private function insertClient(bool $personalAccess, bool $firstParty): string
    {
        $id = Uuid::generate()->toString();
        $this->connection->insert('oauth_clients', [
            'id' => $id,
            'public_id' => (new PublicId())->toString(),
            'name' => $personalAccess ? 'Owner CLI' : 'Baander',
            'redirect' => $personalAccess ? '["http://localhost"]' : '["https://baander.app"]',
            'personal_access_client' => $personalAccess ? 'true' : 'false',
            'first_party' => $firstParty ? 'true' : 'false',
            'created_at' => '2026-11-01 12:00:00+00',
            'updated_at' => '2026-11-01 12:00:00+00',
        ]);

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
