<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Driver middleware that wraps the PostgreSQL driver to return
 * BaanderPostgreSQLPlatform instead of the default PostgreSQLPlatform.
 *
 * This ensures all schema operations use our custom platform that
 * supports pgroonga, GIN with operator classes, and WITH options.
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
                return $this->inner->connect($params);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): PostgreSQLPlatform
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
