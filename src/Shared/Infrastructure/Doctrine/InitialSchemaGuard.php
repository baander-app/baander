<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;

/** Checks catalogs directly so ORM schema filters cannot hide an existing object. */
final class InitialSchemaGuard
{
    public static function assertDatabaseEmpty(Connection $connection): void
    {
        self::assertEmpty($connection, null);
    }

    public static function assertSchemaEmpty(Connection $connection, string $schema): void
    {
        self::assertEmpty($connection, $schema);
    }

    private static function assertEmpty(Connection $connection, ?string $schema): void
    {
        $metadataSchema = (string) $connection->fetchOne('SELECT current_schema()');
        $objects = $connection->fetchAllAssociative(<<<'SQL'
            SELECT identified.type AS kind, n.nspname,
                   COALESCE(identified.name, identified.identity) AS name
            FROM pg_catalog.pg_depend dependency
            JOIN pg_catalog.pg_namespace n ON n.oid = dependency.refobjid
            CROSS JOIN LATERAL pg_catalog.pg_identify_object(
                dependency.classid, dependency.objid, dependency.objsubid
            ) identified
            WHERE dependency.refclassid = 'pg_catalog.pg_namespace'::regclass
              AND n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
              AND (CAST(:schema AS text) IS NULL OR n.nspname = :schema)
              AND dependency.classid <> 'pg_catalog.pg_extension'::regclass
              AND NOT EXISTS (
                  SELECT 1 FROM pg_catalog.pg_depend extension_member
                  WHERE extension_member.classid = dependency.classid
                    AND extension_member.objid = dependency.objid
                    AND extension_member.deptype = 'e'
              )
            ORDER BY n.nspname, kind, name
            SQL, ['schema' => $schema]);

        foreach ($objects as $object) {
            if ($object['kind'] === 'table'
                && $object['nspname'] === $metadataSchema
                && $object['name'] === 'doctrine_migration_versions'
                && $schema === null
                && !$connection->fetchOne(sprintf(
                    'SELECT EXISTS (SELECT 1 FROM %s.doctrine_migration_versions)',
                    $connection->quoteIdentifier($metadataSchema),
                ))
            ) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Database "%s"%s is not empty: existing %s "%s.%s". Initial migration refused before application DDL. Overwriting requires explicit approval for this specific target and a separate manual reset. Do not mark the initial migration as executed.',
                (string) $connection->fetchOne('SELECT current_database()'),
                $schema === null ? '' : sprintf(' schema "%s"', $schema),
                $object['kind'],
                $object['nspname'],
                $object['name'],
            ));
        }
    }
}
