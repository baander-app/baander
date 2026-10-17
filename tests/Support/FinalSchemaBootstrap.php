<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Doctrine\InitialSchemaGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261017000000;
use Psr\Log\NullLogger;

/** Creates the application schema in an empty fixture namespace using provisioned extensions. */
final class FinalSchemaBootstrap
{
    public static function bootstrap(Connection $connection): void
    {
        $schema = $connection->fetchOne('SELECT current_schema()');
        if (!is_string($schema) || $schema === '') {
            throw new \LogicException('The fixture connection needs a current schema.');
        }
        InitialSchemaGuard::assertSchemaEmpty($connection, $schema);
        $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
        require_once dirname(__DIR__, 2) . '/migrations/Version20261017000000.php';
        $connection->transactional(static function (Connection $connection): void {
            $migration = new Version20261017000000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($query->getStatement())) === 1) {
                    continue;
                }
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }
}
