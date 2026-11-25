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
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261125090307;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Login block fields are honeypot input of any length recorded before the bound. The migration
 * cuts each to 255 characters, keeps every block, and then checks the bound on every write.
 */
final class LoginBlockBoundsMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261125090307';
    private const array FIELDS = ['ip_address', 'email', 'field_value', 'user_agent'];

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->schema = 'login_block_bounds_migration_' . (random_bytes(8) |> bin2hex(...));
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

    public function testOverLongFieldsAreCutBetweenCharactersAndNoBlockIsDeleted(): void
    {
        $this->migrateToTheFormerRelease();
        $ascii = $this->insertBlock(str_repeat('1', 300), str_repeat('e', 300), str_repeat('f', 300), str_repeat('u', 300));
        $multibyte = $this->insertBlock('192.0.2.2', 'bot@baander.app', str_repeat('ø', 300), str_repeat('日', 2_000_000));
        $within = $this->insertBlock('2001:db8::1', str_repeat('m', 255), 'filled', 'curl/8');

        $this->migrate();

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM login_blocks'));
        self::assertSame(
            [str_repeat('1', 255), str_repeat('e', 255), str_repeat('f', 255), str_repeat('u', 255)],
            $this->fields($ascii),
        );
        self::assertSame(['192.0.2.2', 'bot@baander.app', str_repeat('ø', 255), str_repeat('日', 255)], $this->fields($multibyte));
        self::assertSame(['2001:db8::1', str_repeat('m', 255), 'filled', 'curl/8'], $this->fields($within));
    }

    public function testEveryWriteIsCheckedAgainstTheBoundInCharacters(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();

        self::assertSame(
            [
                'chk_login_blocks_email_length' => [true, 'CHECK ((char_length(email) <= 255))'],
                'chk_login_blocks_field_value_length' => [true, 'CHECK ((char_length(field_value) <= 255))'],
                'chk_login_blocks_ip_address_length' => [true, 'CHECK ((char_length(ip_address) <= 255))'],
                'chk_login_blocks_user_agent_length' => [true, 'CHECK ((char_length(user_agent) <= 255))'],
            ],
            $this->checks(),
        );
        // 255 two-byte characters are 510 bytes and within the bound.
        $this->insertBlock('192.0.2.3', 'bot@baander.app', str_repeat('ø', 255), 'curl/8');
        foreach (self::FIELDS as $field) {
            $values = ['192.0.2.4', 'bot@baander.app', 'filled', 'curl/8'];
            $values[array_search($field, self::FIELDS, true)] = str_repeat('x', 256);
            // DBAL raises 23514, check_violation, as a generic DriverException.
            $this->assertRejected('23514', fn () => $this->insertBlock(...$values), $field);
        }
    }

    public function testBlocksAreIndexedByAgeForTheRetentionDelete(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();

        self::assertSame(
            'CREATE INDEX idx_login_blocks_created_at ON ' . $this->schema . '.login_blocks USING btree (created_at)',
            $this->connection->fetchOne(
                "SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'idx_login_blocks_created_at'",
            ),
        );
    }

    public function testDowngradingIsRefused(): void
    {
        $migration = $this->migration();

        $this->expectException(IrreversibleMigration::class);
        $migration->down(new Schema());
    }

    /** Builds the schema the release before this migration left: every migration before it. */
    private function migrateToTheFormerRelease(): void
    {
        $files = (glob(dirname(__DIR__, 2) . '/migrations/Version*.php') ?: [])
            |> (static fn (array $files): array => array_filter($files, self::precedesTheMigration(...)))
            |> array_values(...);
        sort($files, SORT_STRING);
        self::assertNotSame([], $files);
        $this->connection->transactional(function () use ($files): void {
            foreach ($files as $file) {
                require_once $file;
                $class = 'DoctrineMigrations\\' . basename($file, '.php');
                $migration = new $class($this->connection, new NullLogger());
                self::assertInstanceOf(AbstractMigration::class, $migration);
                $migration->up(new Schema());
                $this->execute($migration);
            }
        });
    }

    private static function precedesTheMigration(string $file): bool
    {
        return strcmp(basename($file, '.php'), self::MIGRATION) < 0;
    }

    /** Runs the migration under test in one transaction, as Doctrine Migrations does. */
    private function migrate(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        $this->connection->transactional(fn () => $this->execute($migration));
    }

    private function migration(): Version20261125090307
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php';

        return new Version20261125090307($this->connection, new NullLogger());
    }

    /** Runs the planned statements; extensions are provisioned in the public schema, as FinalSchemaBootstrap expects. */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            if (preg_match('/\ACREATE EXTENSION\b/i', $query->getStatement() |> ltrim(...)) === 1) {
                continue;
            }
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertBlock(string $ipAddress, string $email, string $fieldValue, string $userAgent): Uuid
    {
        $id = Uuid::generate();
        $this->connection->insert('login_blocks', [
            'id' => $id->toString(),
            'public_id' => new PublicId()->toString(),
            'ip_address' => $ipAddress,
            'email' => $email,
            'field_value' => $fieldValue,
            'user_agent' => $userAgent,
            'created_at' => '2026-11-20 12:00:00+00',
        ]);

        return $id;
    }

    /** @return list<string> the four stored fields, in table order */
    private function fields(Uuid $id): array
    {
        $row = $this->connection->fetchNumeric(
            'SELECT ip_address, email, field_value, user_agent FROM login_blocks WHERE id = ?',
            [$id->toString()],
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, array{bool, string}> name => whether validated, and its definition */
    private function checks(): array
    {
        $checks = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT conname, convalidated, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE contype = 'c' AND conrelid = 'login_blocks'::regclass ORDER BY conname",
        ) as $row) {
            $checks[$row['conname']] = [(bool) $row['convalidated'], $row['definition']];
        }

        return $checks;
    }

    private function assertRejected(string $sqlState, \Closure $write, string $field): void
    {
        $this->connection->beginTransaction();
        try {
            $write();
            self::fail($field . ' over the bound must be refused with SQLSTATE ' . $sqlState);
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState(), $field);
        } finally {
            $this->connection->rollBack();
        }
    }
}
