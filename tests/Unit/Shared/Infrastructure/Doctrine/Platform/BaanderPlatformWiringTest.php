<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\Platform;

use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderPostgreSQLPlatform;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderPostgreSQLSchemaManager;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderSchemaManagerFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\ServerVersionProvider;
use PHPUnit\Framework\TestCase;

final class BaanderPlatformWiringTest extends TestCase
{
    public function testMiddlewarePreservesNonPostgresPlatform(): void
    {
        $platform = new SQLitePlatform();
        $driver = $this->createStub(Driver::class);
        $driver->method('getDatabasePlatform')->willReturn($platform);

        $wrapped = (new BaanderDriverMiddleware())->wrap($driver);

        self::assertSame($platform, $wrapped->getDatabasePlatform($this->createStub(ServerVersionProvider::class)));
    }

    public function testMiddlewareSelectsCustomPostgresPlatform(): void
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $wrapped = (new BaanderDriverMiddleware())->wrap($driver);

        self::assertInstanceOf(BaanderPostgreSQLPlatform::class, $wrapped->getDatabasePlatform($this->createStub(ServerVersionProvider::class)));
    }

    public function testFactoryCreatesPostgresSchemaManager(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new BaanderPostgreSQLPlatform());

        self::assertInstanceOf(BaanderPostgreSQLSchemaManager::class, (new BaanderSchemaManagerFactory())->createSchemaManager($connection));
    }

    public function testFactoryRejectsNonPostgresConnection(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Baander schema management requires PostgreSQL.');

        (new BaanderSchemaManagerFactory())->createSchemaManager($connection);
    }
}
