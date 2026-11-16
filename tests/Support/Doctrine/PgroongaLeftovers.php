<?php

declare(strict_types=1);

namespace App\Tests\Support\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Removes the Groonga objects that dropped PGroonga indexes left behind, as the only PGroonga cleanup
 * running in the database. Call it before a test runs `VACUUM` or `ANALYZE` on a table with a PGroonga
 * index, or `pgroonga_vacuum()`.
 *
 * PGroonga keeps each index's search data in Groonga objects outside PostgreSQL's catalog and
 * transactions. Dropping an index, its table or its schema leaves them in place. Every `VACUUM` and
 * `ANALYZE` of a table with a PGroonga index, autovacuum's included, and `pgroonga_vacuum()` remove the
 * objects of each index that no longer exists. Nothing serializes two such cleanups: when both reach
 * the same leftover object, the second fails with "failed to remove column ... failed to stat".
 *
 * So this takes SHARE UPDATE EXCLUSIVE on every table that has a PGroonga index, in every schema and
 * in OID order, and then runs `pgroonga_vacuum()` once. That lock conflicts with itself, and autovacuum
 * skips a table whose lock it cannot take at once; a worker that already holds one is cancelled once
 * this has waited `deadlock_timeout`, and the cleanup here finishes what it left. Afterwards the
 * caller's own cleanup finds nothing to remove. Called in autocommit, this commits its own
 * transaction. Called inside a transaction, it runs in a savepoint and the locks last until the
 * caller's transaction ends, so they also cover an `ANALYZE` the caller runs in it.
 *
 * PGroonga before 4.1.0 also counted an index that another session had built and not yet committed as
 * gone, and deleted its objects (upstream GH-1013): on such a version, this cleanup breaks that index too.
 */
final class PgroongaLeftovers
{
    /** Every table with a PGroonga index, schema-qualified and quoted, in OID order. */
    private const string TABLES = <<<'SQL'
        SELECT string_agg(format('%I.%I', n.nspname, c.relname), ', ' ORDER BY c.oid)
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE EXISTS (
            SELECT FROM pg_index i
            JOIN pg_class ic ON ic.oid = i.indexrelid
            JOIN pg_am am ON am.oid = ic.relam
            WHERE i.indrelid = c.oid AND am.amname = 'pgroonga'
        )
        SQL;

    public static function clear(Connection $connection): void
    {
        $connection->transactional(static function (Connection $connection): void {
            $tables = $connection->fetchOne(self::TABLES);
            if (is_string($tables)) {
                // PostgreSQL locks the listed tables one by one, in the order given.
                $connection->executeStatement('LOCK TABLE ' . $tables . ' IN SHARE UPDATE EXCLUSIVE MODE');
            }
            $connection->executeStatement('SELECT pgroonga_vacuum()');
        });
    }
}
