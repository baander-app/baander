-- Run against the intended database with psql -X --set=ON_ERROR_STOP=1 --file=...
-- Candidate review only: no schema or data mutations, including in temporary schemas.
BEGIN TRANSACTION READ ONLY;

SELECT 'database_encoding' AS finding, current_database() AS database_name,
       current_setting('server_encoding') AS encoding
WHERE current_setting('server_encoding') = 'SQL_ASCII';

WITH columns AS (
    SELECT n.nspname AS schema_name, c.relname AS table_name, a.attname AS column_name,
           t.typname AS type_name, a.atttypmod AS type_modifier,
           a.attidentity AS identity_kind,
           pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS default_expression
    FROM pg_catalog.pg_attribute AS a
    JOIN pg_catalog.pg_class AS c ON c.oid = a.attrelid
    JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
    JOIN pg_catalog.pg_type AS t ON t.oid = a.atttypid
    LEFT JOIN pg_catalog.pg_attrdef AS d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
    WHERE a.attnum > 0 AND NOT a.attisdropped AND c.relkind IN ('r', 'p')
      AND n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
), findings AS (
    SELECT schema_name, table_name, column_name, 'timezone_free_timestamp' AS finding FROM columns WHERE type_name = 'timestamp'
    UNION ALL
    SELECT schema_name, table_name, column_name, 'explicit_timestamp_precision' FROM columns WHERE type_name IN ('timestamp', 'timestamptz') AND type_modifier >= 0
    UNION ALL
    SELECT schema_name, table_name, column_name, 'time_with_zone' FROM columns WHERE type_name = 'timetz'
    UNION ALL
    SELECT schema_name, table_name, column_name, 'padded_character' FROM columns WHERE type_name = 'bpchar'
    UNION ALL
    SELECT schema_name, table_name, column_name, 'bounded_varchar_review_contract' FROM columns WHERE type_name = 'varchar' AND type_modifier >= 0
    UNION ALL
    SELECT schema_name, table_name, column_name, 'money_type' FROM columns WHERE type_name = 'money'
    UNION ALL
    SELECT schema_name, table_name, column_name, 'sequence_default_without_identity' FROM columns WHERE identity_kind = '' AND default_expression ~ '^nextval\('
    UNION ALL
    SELECT schema_name, table_name, column_name, 'nonstandard_column_name' FROM columns WHERE column_name !~ '^[a-z_][a-z0-9_]*$'
)
SELECT * FROM findings ORDER BY schema_name, table_name, column_name, finding;

SELECT n.nspname AS schema_name, c.relname AS table_name, 'nonstandard_table_name' AS finding
FROM pg_catalog.pg_class AS c
JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p') AND n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
  AND c.relname !~ '^[a-z_][a-z0-9_]*$'
ORDER BY 1, 2;

SELECT n.nspname AS schema_name, c.relname AS table_name, r.rulename AS rule_name
FROM pg_catalog.pg_rewrite AS r
JOIN pg_catalog.pg_class AS c ON c.oid = r.ev_class
JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
WHERE r.rulename <> '_RETURN' AND n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
ORDER BY 1, 2, 3;

SELECT n.nspname AS schema_name, c.relname AS child_table, p.relname AS parent_table
FROM pg_catalog.pg_inherits AS i
JOIN pg_catalog.pg_class AS c ON c.oid = i.inhrelid
JOIN pg_catalog.pg_class AS p ON p.oid = i.inhparent
JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
WHERE NOT c.relispartition AND c.relkind IN ('r', 'p')
  AND n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
ORDER BY 1, 2, 3;

COMMIT;
