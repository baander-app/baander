<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\SchemaManagerFactory;

/**
 * Produces BaanderPostgreSQLSchemaManager which captures pgroonga/gin index
 * metadata during introspection.
 *
 * Registered via doctrine.yaml: schema_manager_factory
 */
class BaanderSchemaManagerFactory implements SchemaManagerFactory
{
    public function createSchemaManager(Connection $connection): BaanderPostgreSQLSchemaManager
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new \LogicException('Baander schema management requires PostgreSQL.');
        }

        return new BaanderPostgreSQLSchemaManager($connection, $platform);
    }
}
