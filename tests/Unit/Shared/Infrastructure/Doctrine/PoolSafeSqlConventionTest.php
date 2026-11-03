<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Tests\Support\Doctrine\PoolSafeSqlScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Application SQL runs through a transaction-mode PgBouncer, which lends a server connection to
 * a client for one transaction only. SQL keeps its state inside the transaction: `SET LOCAL`,
 * `set_config(…, true)`, transaction-scoped advisory locks, `ON COMMIT DROP` temporary tables.
 */
final class PoolSafeSqlConventionTest extends TestCase
{
    /**
     * Reviewed session-scoped SQL: path => number of uses. Add an entry only after checking that
     * the state is the same for every client of the pool.
     */
    private const array SESSION_STATE = [
        // The connect-time `SET TIME ZONE 'UTC'`. Every client sets the same zone, and PgBouncer
        // tracks TimeZone per client and restores it whenever it hands that client a connection.
        'src/Shared/Infrastructure/Doctrine/Platform/BaanderDriverMiddleware.php' => 1,
    ];

    public function testApplicationSqlKeepsItsStateInsideTheTransaction(): void
    {
        $violations = PoolSafeSqlScanner::scan(dirname(__DIR__, 5), ['src', 'migrations', 'bin'], self::SESSION_STATE);

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /** @return iterable<string, array{string}> */
    public static function sessionScopedSql(): iterable
    {
        yield 'session advisory lock' => ["\$c->executeQuery('SELECT pg_advisory_lock(hashtextextended(:id, 0))', ['id' => \$id]);"];
        yield 'session try advisory lock' => ['$c->fetchOne("SELECT pg_try_advisory_lock_shared(42)");'];
        yield 'advisory unlock' => ["\$c->executeQuery('SELECT pg_advisory_unlock_all()');"];
        yield 'listen' => ["\$c->executeStatement('LISTEN query_changes');"];
        yield 'unlisten after a statement' => ["\$c->executeStatement('SELECT 1; UNLISTEN *');"];
        yield 'named prepare' => ["\$c->executeStatement('PREPARE lookup (uuid) AS SELECT * FROM users WHERE id = \$1');"];
        yield 'deallocate' => ["\$c->executeStatement('DEALLOCATE ALL');"];
        yield 'hold cursor' => ["\$c->executeStatement('DECLARE songs CURSOR WITH HOLD FOR SELECT id FROM songs');"];
        yield 'temporary table' => ["\$c->executeStatement('CREATE TEMPORARY TABLE pending (id uuid) ON COMMIT DELETE ROWS');"];
        yield 'session set_config' => ["\$c->executeQuery(\"SELECT set_config('statement_timeout', '1s', false)\");"];
        yield 'set_config with a bound scope' => ["\$c->executeQuery(\"SELECT set_config('lock_timeout', :lock, :local)\");"];
        yield 'session set' => ["\$c->executeStatement(\"SET statement_timeout = '1000ms'\");"];
        yield 'set session' => ["\$c->executeStatement('SET SESSION work_mem TO \\'64MB\\'');"];
        yield 'set time zone' => ["\$c->executeStatement(\"SET TIME ZONE 'Europe/Copenhagen'\");"];
        yield 'set with a concatenated name' => ["\$c->executeStatement('SET ' . \$name . ' = 1');"];
        yield 'set inside a function body' => [<<<'PHP'
            $this->addSql(<<<'SQL'
                CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS $$
                BEGIN
                    SET search_path TO public;
                END
                $$
                SQL);
            PHP];
        yield 'set after an interpolated part' => ["\$c->executeStatement(\"BEGIN; {\$x}; SET work_mem = '1GB'\");"];
    }

    #[DataProvider('sessionScopedSql')]
    public function testSessionScopedSqlFails(string $statement): void
    {
        $violations = PoolSafeSqlScanner::scanSource('src/Probe.php', "<?php\n" . $statement . "\n");

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringStartsWith('src/Probe.php:', $violations[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function transactionScopedSql(): iterable
    {
        yield 'transaction advisory lock' => ["\$c->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))', ['id' => \$id]);"];
        yield 'transaction try advisory lock' => ['$c->fetchOne("SELECT pg_try_advisory_xact_lock_shared(42)");'];
        yield 'set transaction' => ["\$c->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');"];
        yield 'set local' => ["\$c->executeStatement(sprintf(\"SET LOCAL statement_timeout = '%s'\", \$timeout));"];
        yield 'set constraints' => ["\$c->executeStatement('SET CONSTRAINTS ALL DEFERRED');"];
        yield 'local set_config' => ["\$c->executeQuery(\"SELECT set_config('statement_timeout', :statement, true), set_config('lock_timeout', concat(:lock, 'ms'), true)\");"];
        yield 'dropped temporary table' => ["\$c->executeStatement('CREATE TEMP TABLE pending (id uuid) ON COMMIT DROP');"];
        yield 'update set' => ["\$c->executeStatement('UPDATE songs SET title = :title WHERE id = :id');"];
        yield 'update set across concatenation' => ["\$c->executeStatement('UPDATE ' . \$table . ' SET title = :title');"];
        yield 'update set across interpolation' => ["\$c->executeStatement(\"UPDATE {\$table} SET title = :title\");"];
        yield 'conflict update set' => ["\$c->executeStatement('INSERT INTO t (id) VALUES (1) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id');"];
        yield 'database default' => ["\$this->addSql(\"ALTER DATABASE baander SET timezone = 'UTC'\");"];
        yield 'foreign key action' => ["#[ORM\\JoinColumn(name: 'song_id', referencedColumnName: 'id', onDelete: 'SET NULL')]\nfinal class Probe {}"];
        yield 'function SET clause' => ["\$this->addSql('CREATE FUNCTION f() RETURNS int LANGUAGE sql SET search_path = pg_catalog AS \\'SELECT 1\\'');"];
        yield 'notify' => ["\$c->executeStatement(\"SELECT pg_notify('query_changes', :payload)\");"];
        yield 'driver prepare' => ["\$c->prepare('SELECT 1')->executeQuery();"];
        yield 'prose' => ["\$io->error('Set APP_URL to https://baander.app. Prepare the bundle, then listen for alerts.');"];
        yield 'comment' => ["// SELECT pg_advisory_lock(1); SET statement_timeout = 0\n/** LISTEN changes */\n\$x = 1;"];
    }

    #[DataProvider('transactionScopedSql')]
    public function testTransactionScopedSqlPasses(string $statement): void
    {
        self::assertSame([], PoolSafeSqlScanner::scanSource('src/Probe.php', "<?php\n" . $statement . "\n"));
    }

    public function testAnAllowanceCoversOnlyItsReviewedCount(): void
    {
        $source = "<?php\n\$c->exec(\"SET TIME ZONE 'UTC'\");\n";

        self::assertSame([], PoolSafeSqlScanner::scanSource('src/Probe.php', $source, 1));
        self::assertCount(1, PoolSafeSqlScanner::scanSource('src/Probe.php', $source, 0), 'An unreviewed use fails.');
        self::assertCount(1, PoolSafeSqlScanner::scanSource('src/Probe.php', $source, 2), 'A stale allowance fails.');
    }
}
