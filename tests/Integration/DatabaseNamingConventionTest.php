<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Doctrine\Platform\UnmanagedCustomIndex;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Index and constraint names follow docs-book/part-2-developer-guide/database-naming.md.
 *
 * Reads the catalog of the fully migrated disposable PostgreSQL database.
 */
final class DatabaseNamingConventionTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const MAX_IDENTIFIER_BYTES = 63;

    public function testEveryIndexAndConstraintNameFollowsTheConvention(): void
    {
        $violations = [];
        foreach ($this->objects() as $object) {
            $name = $object['name'];
            $table = $object['table'];
            if (strlen($name) > self::MAX_IDENTIFIER_BYTES) {
                $violations[] = $name . ': longer than 63 bytes';
            }

            if ($object['kind'] === 'p') {
                if ($name !== $table . '_pkey') {
                    $violations[] = $name . ': expected ' . $table . '_pkey';
                }
                continue;
            }

            $prefix = match ($object['kind']) {
                'f' => 'fk',
                'u' => 'uniq',
                default => $object['unique'] ? 'uniq' : 'idx',
            };
            $base = $prefix . '_' . $table . '_' . str_replace(',', '_', $object['columns']);
            $suffix = match (true) {
                $object['method'] === 'btree' => '',
                $object['method'] === 'gin' && $object['operator_class'] === 'gin_trgm_ops' => '_trgm',
                $object['method'] === 'pgroonga' => '_pgroonga',
                default => '_' . $object['method'],
            };

            // Partial, expression and polymorphic indexes, and names that would exceed the
            // identifier limit, are named by purpose: only the prefix and table are fixed.
            $byPurpose = $object['partial'] || str_contains($object['columns'], '<expression>')
                || UnmanagedCustomIndex::tryFrom($name) !== null
                || strlen($base . $suffix) > self::MAX_IDENTIFIER_BYTES;
            if ($byPurpose) {
                if (!str_starts_with($name, $prefix . '_' . $table . '_') || !str_ends_with($name, $suffix)) {
                    $violations[] = $name . ': expected ' . $prefix . '_' . $table . '_<purpose>' . $suffix;
                }
                continue;
            }

            // PGroonga indexes other than the standard full-text one carry a qualifier.
            $matches = $object['method'] === 'pgroonga'
                ? preg_match('/^' . preg_quote($base . $suffix, '/') . '(_[a-z0-9]+)?$/', $name) === 1
                : $name === $base . $suffix;
            if (!$matches) {
                $violations[] = $name . ': expected ' . $base . $suffix;
            }
        }

        self::assertSame([], $violations);
    }

    public function testMappingsAndForeignKeyDeclarationsMatchTheCatalog(): void
    {
        $metadata = $this->manager->getMetadataFactory()->getAllMetadata();

        // Only the migrations console commands hide Doctrine Migrations' own metadata table.
        self::assertSame([], array_values(array_filter(
            (new SchemaTool($this->manager))->getUpdateSchemaSql($metadata),
            static fn (string $sql): bool => $sql !== 'DROP TABLE doctrine_migration_versions',
        )));
    }

    public function testRenameMigrationRoundTripChangesOnlyNames(): void
    {
        $latest = array_column($this->objects(), 'name');
        // Later migrations dropped some of the renamed indexes and tables; restore them so the rename can be reversed.
        $this->runMigration('Version20261006360000', 'down');
        $this->runMigration('Version20261006300000', 'down');
        $this->runMigration('Version20261006260000', 'down');
        $this->runMigration('Version20261006240000', 'down');
        $before = $this->objects();
        $definitions = $this->definitions($before);

        $this->runMigration('Version20261006230000', 'down');
        $restored = $this->objects();
        self::assertSame($definitions, $this->definitions($restored));
        $restoredNames = array_column($restored, 'name');
        foreach (['_fkpush_subscriptions_user_id', 'fk_57514dd4613fecdf', 'library_file_path_unique', 'idx_35d5e9c7a76ed395', 'users_email_unique'] as $legacy) {
            self::assertContains($legacy, $restoredNames);
        }

        $this->runMigration('Version20261006230000', 'up');
        $after = $this->objects();
        self::assertSame($definitions, $this->definitions($after));
        self::assertSame(array_column($before, 'name'), array_column($after, 'name'));

        $this->runMigration('Version20261006240000', 'up');
        $this->runMigration('Version20261006260000', 'up');
        $this->runMigration('Version20261006300000', 'up');
        $this->runMigration('Version20261006360000', 'up');
        self::assertSame($latest, array_column($this->objects(), 'name'));
    }

    private function runMigration(string $version, string $direction): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
        $class = 'DoctrineMigrations\\' . $version;
        $connection = $this->manager->getConnection();
        $migration = new $class($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * Definitions without names, so a rename leaves them unchanged.
     *
     * @param list<array{name: string, table: string, definition: string}> $objects
     *
     * @return list<string>
     */
    private function definitions(array $objects): array
    {
        $definitions = array_map(
            static fn (array $object): string => $object['table'] . ': ' . str_replace(' ' . $object['name'] . ' ON ', ' ON ', $object['definition']),
            $objects,
        );
        sort($definitions);

        return $definitions;
    }

    /**
     * Foreign keys, unique constraints and indexes, including primary keys; constraint-backed
     * indexes are reported once, as their constraint. Doctrine Migrations owns its metadata table.
     *
     * @return list<array{kind: string, table: string, name: string, columns: string, unique: bool, partial: bool, method: string, operator_class: string, definition: string}>
     */
    private function objects(): array
    {
        $rows = $this->manager->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT c.contype::text AS kind, t.relname AS table, c.conname AS name,
                   (SELECT string_agg(a.attname, ',' ORDER BY k.ord)
                      FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord)
                      JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS columns,
                   c.contype = 'u' AS unique, false AS partial,
                   CASE WHEN c.contype = 'f' THEN 'btree' ELSE coalesce(am.amname, 'btree') END AS method,
                   CASE WHEN c.contype = 'f' THEN '' ELSE coalesce(opc.opcname, '') END AS operator_class, pg_get_constraintdef(c.oid) AS definition
              FROM pg_constraint c
              JOIN pg_class t ON t.oid = c.conrelid
              LEFT JOIN pg_class ic ON ic.oid = c.conindid
              LEFT JOIN pg_am am ON am.oid = ic.relam
              LEFT JOIN pg_index i ON i.indexrelid = c.conindid
              LEFT JOIN pg_opclass opc ON opc.oid = i.indclass[0]
             WHERE t.relnamespace = current_schema()::regnamespace AND c.contype IN ('f', 'u', 'p', 'x')
               AND t.relname <> 'doctrine_migration_versions'
            UNION ALL
            SELECT 'i', t.relname, ic.relname,
                   (SELECT string_agg(coalesce(a.attname, '<expression>'), ',' ORDER BY k.ord)
                      FROM unnest(i.indkey::int2[]) WITH ORDINALITY k(attnum, ord)
                      LEFT JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum AND k.attnum <> 0),
                   i.indisunique, i.indpred IS NOT NULL, am.amname, opc.opcname, pg_get_indexdef(i.indexrelid)
              FROM pg_index i
              JOIN pg_class ic ON ic.oid = i.indexrelid
              JOIN pg_class t ON t.oid = i.indrelid
              JOIN pg_am am ON am.oid = ic.relam
              JOIN pg_opclass opc ON opc.oid = i.indclass[0]
             WHERE t.relnamespace = current_schema()::regnamespace AND t.relname <> 'doctrine_migration_versions'
               AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.indexrelid AND c.contype IN ('u', 'p', 'x'))
             ORDER BY 2, 3
            SQL);

        return array_map(static fn (array $row): array => [
            'kind' => (string) $row['kind'],
            'table' => (string) $row['table'],
            'name' => (string) $row['name'],
            'columns' => (string) $row['columns'],
            'unique' => (bool) $row['unique'],
            'partial' => (bool) $row['partial'],
            'method' => (string) $row['method'],
            'operator_class' => (string) $row['operator_class'],
            'definition' => (string) $row['definition'],
        ], $rows);
    }
}
