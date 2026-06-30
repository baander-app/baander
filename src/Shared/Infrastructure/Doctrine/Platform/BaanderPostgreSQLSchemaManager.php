<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;

/**
 * Extends PostgreSQLSchemaManager to capture index method (pgroonga, gin, etc.)
 * and operator classes during introspection.
 *
 * This ensures doctrine:migrations:diff can compare pgroonga/trgm indexes
 * declared in entity attributes against the actual database state.
 */
class BaanderPostgreSQLSchemaManager extends PostgreSQLSchemaManager
{
    public function __construct(
        Connection $connection,
        PostgreSQLPlatform $platform,
    ) {
        parent::__construct($connection, $platform);
    }

    /**
     * Override to include pg_am.amname and pg_index.indclass in the query.
     */
    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = [];
        $conditions = [];

        if ($tableName !== null) {
            if (str_contains($tableName, '.')) {
                [$schemaName, $tableName] = explode('.', $tableName);
                $conditions[] = 'n.nspname = ?';
                $params[]     = $schemaName;
            } else {
                $conditions[] = 'n.nspname = ANY(current_schemas(false))';
            }
            $conditions[] = 'c.relname = ?';
            $params[]     = $tableName;
        }
        $conditions[] = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')";

        $sql = <<<'SQL'
        SELECT
               quote_ident(n.nspname) AS schema_name,
               quote_ident(c.relname) AS table_name,
               quote_ident(ic.relname) AS relname,
               i.indisunique,
               i.indisprimary,
               i.indkey,
               i.indrelid,
               pg_get_expr(indpred, indrelid) AS "where",
               quote_ident(attname) AS attname,
               am.amname,
               i.indclass
          FROM pg_index i
               JOIN pg_class AS c ON c.oid = i.indrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_class AS ic ON ic.oid = i.indexrelid
               JOIN pg_am am ON am.oid = ic.relam
               JOIN LATERAL UNNEST(i.indkey) WITH ORDINALITY AS keys(attnum, ord)
                    ON TRUE
               JOIN pg_attribute a
                    ON a.attrelid = c.oid
                        AND a.attnum = keys.attnum
         WHERE %s
         ORDER BY 1, 2, keys.ord
        SQL;

        $sql = sprintf($sql, implode(' AND ', $conditions));

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * Fully overridden to map amname → flags and operator_class → options.
     *
     * Parent class only captures btree indexes. We extend this to understand
     * pgroonga, gin, gist, and operator classes like gin_trgm_ops.
     */
    protected function _getPortableTableIndexesList(array $rows, string $tableName): array
    {
        $result = [];

        foreach ($rows as $row) {
            $indexName = $row['relname'];
            $keyName = (bool) $row['indisprimary'] ? 'primary' : strtolower($indexName);
            $amname = $row['amname'] ?? 'btree';

            if (!isset($result[$keyName])) {
                $flags = [];
                if ($amname !== 'btree') {
                    $flags[] = $amname;
                }

                $options = ['lengths' => []];

                if (!empty($row['where'])) {
                    $options['where'] = $row['where'];
                }

                // Resolve operator class for non-default methods
                $operatorClass = $this->resolveOperatorClass(
                    $row['indclass'] ?? null,
                    $amname,
                );
                if ($operatorClass !== null) {
                    $options['operator_class'] = $operatorClass;
                }

                $result[$keyName] = [
                    'name' => $indexName,
                    'columns' => [],
                    'unique' => (bool) $row['indisunique'],
                    'primary' => (bool) $row['indisprimary'],
                    'flags' => $flags,
                    'options' => $options,
                ];
            }

            $result[$keyName]['columns'][] = $row['attname'];
            $result[$keyName]['options']['lengths'][] = null;
        }

        $indexes = [];
        foreach ($result as $indexKey => $data) {
            $indexes[$indexKey] = new Index(
                $data['name'],
                $data['columns'],
                $data['unique'],
                $data['primary'],
                $data['flags'],
                $data['options'],
            );
        }

        return $indexes;
    }

    /**
     * Resolve operator class name from indclass OIDs.
     *
     * Returns null if the operator class is the default for the method
     * or if it can't be resolved.
     */
    private function resolveOperatorClass(?string $indclassArray, string $amname): ?string
    {
        if ($indclassArray === null || $indclassArray === '') {
            return null;
        }

        $oids = trim($indclassArray, '{}');
        if ($oids === '') {
            return null;
        }

        $oidList = explode(',', $oids);
        $firstOid = trim($oidList[0]);

        if (!is_numeric($firstOid) || (int) $firstOid === 0) {
            return null;
        }

        try {
            $opcName = $this->connection->executeQuery(
                'SELECT opcname FROM pg_opclass WHERE oid = ?',
                [(int) $firstOid],
            )->fetchOne();

            if ($opcName === false || $opcName === null) {
                return null;
            }

            $opcName = (string) $opcName;

            // Default operator classes don't need explicit declaration
            $defaultsByMethod = [
                'btree' => ['int4_ops', 'text_ops', 'text_pattern_ops', 'varchar_ops', 'uuid_ops', 'bool_ops', 'timestamp_ops', 'timestamptz_ops', 'numeric_ops', 'float8_ops', 'int8_ops', 'int2_ops', 'oid_ops', 'bpchar_ops', 'bpchar_pattern_ops', 'date_ops', 'time_ops', 'timetz_ops', 'numeric_ops', 'decimal_ops', 'float4_ops', 'char_ops', 'char_pattern_ops'],
                'gin' => ['array_ops', 'jsonb_ops'],
                'gist' => [],
                'pgroonga' => [],
                'hash' => ['int4_ops', 'text_ops', 'uuid_ops', 'int8_ops'],
                'brin' => [],
            ];

            $defaultList = $defaultsByMethod[$amname] ?? [];
            if (in_array($opcName, $defaultList, true)) {
                return null;
            }

            return $opcName;
        } catch (\Throwable) {
            return null;
        }
    }
}
