<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\Exception\DatabaseTimeZoneException;
use App\Shared\Infrastructure\Doctrine\DatabaseTimeZoneGuard;
use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Swoole\DatabaseTimeZoneStartupCheck;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/** Startup reads the database's default zone, not the zone each connection sets for itself. */
final class DatabaseTimeZoneGuardTest extends TestCase
{
    private Connection $admin;
    private string $database;
    private string $url;
    private string $password;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL with permission to create test databases.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->password = (string) ($parameters['password'] ?? '');
        $this->admin = DriverManager::getConnection($parameters);
        $this->database = 'time_zone_guard_test_' . bin2hex(random_bytes(8));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->admin->quoteIdentifier($this->database));
        $this->url = preg_replace('~^([^/]+//[^/]+/)[^?]*~', '${1}' . $this->database, $url) ?? throw new \LogicException('Invalid test URL.');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->admin->executeStatement('DROP DATABASE ' . $this->admin->quoteIdentifier($this->database) . ' WITH (FORCE)');
        }
        if (isset($this->admin)) {
            $this->admin->close();
        }
    }

    public function testStartupRefusesANonUtcDefaultEvenThoughTheConnectionSetsUtc(): void
    {
        $this->setDatabaseZone('Europe/Copenhagen');
        $connection = DedicatedConnectionFactory::fromUrl($this->url);
        try {
            self::assertSame('UTC', $connection->fetchOne('SHOW TimeZone'), 'The connection pins its own session zone.');
            $this->assertRefused(static fn () => DatabaseTimeZoneGuard::assertUtcDefault($connection), 'Europe/Copenhagen');
        } finally {
            $connection->close();
        }
        $this->assertRefused(fn () => (new DatabaseTimeZoneStartupCheck($this->url))->boot(), 'Europe/Copenhagen');
    }

    public function testStartupRefusesARoleDefaultThatOverridesTheDatabase(): void
    {
        $this->setDatabaseZone('UTC');
        $this->admin->executeStatement('ALTER ROLE CURRENT_USER IN DATABASE ' . $this->admin->quoteIdentifier($this->database) . " SET timezone = 'America/New_York'");

        $this->assertRefused(fn () => DatabaseTimeZoneGuard::assertUtcDefaultAt($this->url), 'America/New_York');
    }

    public function testStartupProceedsAgainstUtcAndItsAliases(): void
    {
        foreach (['UTC', 'Etc/UTC', 'etc/zulu'] as $zone) {
            $this->setDatabaseZone($zone);
            (new DatabaseTimeZoneStartupCheck($this->url))->boot();
            DatabaseTimeZoneGuard::assertUtcDefaultAt($this->url);
        }
        $this->addToAssertionCount(1);
    }

    public function testStartupRefusesAZeroOffsetZoneThatIsNotUtc(): void
    {
        $this->setDatabaseZone('Africa/Abidjan');

        $this->assertRefused(fn () => DatabaseTimeZoneGuard::assertUtcDefaultAt($this->url), 'Africa/Abidjan');
    }

    private function setDatabaseZone(string $zone): void
    {
        $this->admin->executeStatement('ALTER DATABASE ' . $this->admin->quoteIdentifier($this->database) . ' SET timezone = ' . $this->admin->quote($zone));
    }

    private function assertRefused(\Closure $check, string $zone): void
    {
        try {
            $check();
            self::fail('Startup must refuse a database whose default zone is ' . $zone . '.');
        } catch (DatabaseTimeZoneException $error) {
            self::assertStringContainsString("'" . $zone . "'", $error->getMessage());
            if ($this->password !== '') {
                self::assertStringNotContainsString($this->password, $error->getMessage());
            }
        }
    }
}
