<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaManagerFactory;

/**
 * Produces BaanderPostgreSQLSchemaManager which captures pgroonga/gin index
 * metadata during introspection.
 *
 * Registered via doctrine.yaml: schema_manager_factory
 */
class BaanderSchemaManagerFactory implements SchemaManagerFactory
{
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        return new BaanderPostgreSQLSchemaManager(
            $connection,
            $connection->getDatabasePlatform(),
        );
    }
}
