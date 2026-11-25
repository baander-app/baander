<?php

declare(strict_types=1);

namespace Baander\Deployment\Tests;

use Baander\Deployment\Database\Pooler;
use PHPUnit\Framework\TestCase;

/** The credentials helper's two role layouts, read from the script the database image installs. */
final class PoolerRoleLayoutTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/docker/postgres/pooler/baander-pooler-credentials');
    }

    public function testTheSeparateLayoutCreatesTheApplicationRoleAsANonSuperuserOwner(): void
    {
        self::assertSame(1, preg_match("/OWNER_SQL = '''(.+?)'''\\n/s", self::script(), $sql));
        self::assertStringContainsString('ALTER ROLE :"role" WITH LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', $sql[1]);
        self::assertStringContainsString('ALTER DATABASE :"database" OWNER TO :"role";', $sql[1]);
        self::assertStringContainsString('ALTER ROLE :"control" WITH LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE', self::script());
        self::assertStringContainsString('ALTER ROLE :"superuser" PASSWORD NULL;', self::script());
    }

    public function testTheHelperCreatesExactlyTheExtensionsTheFirstMigrationCreates(): void
    {
        self::assertSame(1, preg_match('/^EXTENSIONS = \((.+)\)$/m', self::script(), $list));
        preg_match_all("/'([a-z_-]+)'/", $list[1], $helper);
        preg_match_all('/CREATE EXTENSION IF NOT EXISTS "?([a-z_-]+)"?/', (string) file_get_contents(dirname(__DIR__, 3) . '/migrations/Version20261017000000.php'), $migration);
        preg_match_all('/CREATE EXTENSION IF NOT EXISTS "([a-z_-]+)"/', (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/postgres-extensions.sql'), $fixture);
        preg_match_all('/CREATE EXTENSION IF NOT EXISTS "([a-z_-]+)"/', (string) file_get_contents(dirname(__DIR__, 3) . '/compose.test.yaml'), $testStack);
        sort($migration[1]);

        self::assertSame($migration[1], $helper[1]);
        // The test stack creates them in its database and template from the fixture's statements.
        self::assertSame($fixture[1], $testStack[1]);
        sort($fixture[1]);
        self::assertSame($migration[1], $fixture[1]);
    }

    public function testTheDevelopmentLayoutKeepsLoggingInAsTheApplicationRoleOverTheNetwork(): void
    {
        $helper = Pooler::credentialsHelper('database-image', 'baander-private', 'credentials', 'baander', 'development-password', 'baander', 'database');

        self::assertSame(['--network', 'baander-private'], array_slice($helper['arguments'], 0, 2));
        self::assertArrayNotHasKey('BAANDER_POOLER_SUPERUSER', $helper['environment']);
        self::assertSame('database', $helper['environment']['BAANDER_POOLER_SERVER_HOST']);
        self::assertStringNotContainsString(Pooler::SOCKET_DIRECTORY, implode(' ', $helper['arguments']));
    }
}
