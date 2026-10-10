<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Driver middleware that wraps the PostgreSQL driver to return
 * BaanderPostgreSQLPlatform instead of the default PostgreSQLPlatform.
 *
 * This ensures all schema operations use our custom platform that
 * supports pgroonga, GIN with operator classes, and WITH options.
 *
 * Every PostgreSQL connection also pins its session time zone to UTC, so the
 * text form of an instant and zone-dependent SQL (`::date`, `date_trunc`) do
 * not depend on the server's or the client's default zone.
 */
#[AsMiddleware(priority: 1)]
class BaanderDriverMiddleware implements MiddlewareInterface
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) implements Driver {
            public function __construct(
                private readonly Driver $inner,
            ) {
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Driver\Connection {
                $connection = $this->inner->connect($params);
                if ($this->inner->getDatabasePlatform($connection) instanceof PostgreSQLPlatform) {
                    $connection->exec("SET TIME ZONE 'UTC'");
                }

                return $connection;
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                $platform = $this->inner->getDatabasePlatform($versionProvider);

                if ($platform instanceof PostgreSQLPlatform && !($platform instanceof BaanderPostgreSQLPlatform)) {
                    return new BaanderPostgreSQLPlatform();
                }

                return $platform;
            }

            public function getExceptionConverter(): Driver\API\ExceptionConverter
            {
                return $this->inner->getExceptionConverter();
            }
        };
    }
}
