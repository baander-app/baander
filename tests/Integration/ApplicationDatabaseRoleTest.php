<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * The test stack's application role has the preset's attributes: it owns the database and
 * is not a superuser, so it cannot run a program on the database server or grant itself a
 * role. Unlike the preset's role, it may create databases and end an autovacuum worker's
 * connection, which the migration tests' DROP DATABASE WITH (FORCE) needs.
 */
final class ApplicationDatabaseRoleTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires the disposable PostgreSQL of the test stack.');
        }
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testTheApplicationRoleOwnsTheDatabaseWithoutSuperuserPrivileges(): void
    {
        self::assertSame(
            ['rolsuper' => false, 'rolcreaterole' => false, 'rolreplication' => false, 'rolbypassrls' => false, 'owner' => true],
            array_map(
                static fn (mixed $value): bool => (bool) $value,
                $this->connection->fetchAssociative(
                    'SELECT rolsuper, rolcreaterole, rolreplication, rolbypassrls,'
                    . ' (SELECT datdba FROM pg_database WHERE datname = current_database()) = oid AS owner'
                    . ' FROM pg_roles WHERE rolname = current_user',
                ) ?: [],
            ),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function privilegedStatements(): iterable
    {
        yield 'COPY to a program' => ["COPY (SELECT 1) TO PROGRAM 'true'"];
        yield 'COPY from a program' => ["COPY copy_probe FROM PROGRAM 'true'"];
        yield 'create a role' => ['CREATE ROLE intruder'];
        yield 'grant itself superuser' => ['ALTER ROLE CURRENT_USER SUPERUSER'];
        yield 'read a server file' => ["SELECT pg_read_file('/etc/passwd')"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privilegedStatements')]
    public function testAStatementThatNeedsASuperuserIsRefused(string $sql): void
    {
        $this->connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS copy_probe (value int)');
        try {
            $this->connection->executeStatement($sql);
            self::fail('The application role ran: ' . $sql);
        } catch (DriverException $refused) {
            self::assertSame('42501', $refused->getSQLState(), $refused->getMessage());
        }
    }
}
