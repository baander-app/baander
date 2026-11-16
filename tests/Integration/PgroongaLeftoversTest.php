<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Support\Doctrine\PgroongaLeftovers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * PgroongaLeftovers::clear removes the Groonga objects of dropped PGroonga indexes, keeps those of an
 * index another session has not committed yet, and holds every table with a PGroonga index against
 * autovacuum for as long as its transaction lasts. A throwaway schema holds a committed PGroonga table.
 */
final class PgroongaLeftoversTest extends TestCase
{
    private const string INDEX_OPTIONS = "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'";

    /** The SQLSTATE of a lock request refused with NOWAIT or after lock_timeout. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private Connection $session;
    private Connection $other;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable, migrated PostgreSQL database with PGroonga.');
        }

        $this->schema = 'pgroonga_leftovers_' . (random_bytes(8) |> bin2hex(...));
        $this->session = ArtistImportIdentityRace::connect($this->schema);
        $this->session->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->session->executeStatement('CREATE TABLE titles (id integer PRIMARY KEY, title text NOT NULL)');
        $this->session->executeStatement('CREATE INDEX idx_titles_title_pgroonga ON titles USING pgroonga (title) WITH (' . self::INDEX_OPTIONS . ')');
        $this->other = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        foreach (array_filter([$this->other ?? null, $this->session ?? null]) as $connection) {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        if (isset($this->session)) {
            $this->session->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->session->close();
        }
        if (isset($this->other)) {
            $this->other->close();
        }
    }

    public function testClearRemovesTheObjectsADroppedSchemaLeftBehind(): void
    {
        $connection = $this->session;
        $sources = $connection->fetchOne('SELECT pgroonga_table_name(:index)', ['index' => $this->schema . '.idx_titles_title_pgroonga']);
        $connection->beginTransaction();
        $connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        // Until the drop commits, every other session, autovacuum's included, still sees the index and keeps its objects.
        self::assertContains($sources, $this->leftovers(), 'The dropped index leaves its objects in the Groonga store.');
        $connection->commit();

        PgroongaLeftovers::clear($connection);

        self::assertSame([], $this->leftovers());
    }

    public function testClearKeepsTheObjectsOfAnIndexAnotherSessionHasNotCommitted(): void
    {
        // The table is new: building an index on an existing table holds a SHARE lock that clear() would wait for.
        $this->other->beginTransaction();
        $this->other->executeStatement('CREATE TABLE fresh_titles (id integer PRIMARY KEY, title text NOT NULL)');
        $this->other->executeStatement('CREATE INDEX idx_fresh_titles_title_pgroonga ON fresh_titles USING pgroonga (title) WITH (' . self::INDEX_OPTIONS . ')');

        PgroongaLeftovers::clear($this->session);

        $this->other->commit();
        $this->other->executeStatement("INSERT INTO fresh_titles (id, title) VALUES (1, 'Fresh title')");
        $this->other->executeStatement('SET enable_seqscan = off');
        self::assertSame(['Fresh title'], $this->other->fetchFirstColumn("SELECT title FROM fresh_titles WHERE title &@~ 'fresh'"));
    }

    public function testClearInsideATransactionHoldsEveryPgroongaTableUntilTheTransactionEnds(): void
    {
        $connection = $this->session;
        $connection->beginTransaction();
        PgroongaLeftovers::clear($connection);

        foreach (['public.songs', $this->schema . '.titles'] as $table) {
            self::assertSame(self::LOCK_NOT_AVAILABLE, $this->lockFromTheOtherSession($table, wait: false), $table . ' is held.');
        }
        $connection->commit();
        foreach (['public.songs', $this->schema . '.titles'] as $table) {
            self::assertNull($this->lockFromTheOtherSession($table, wait: true), $table . ' is released.');
        }
    }

    public function testClearInAutocommitHoldsNoLockAfterItReturns(): void
    {
        PgroongaLeftovers::clear($this->session);

        self::assertNull($this->lockFromTheOtherSession('public.songs', wait: true));
    }

    /**
     * Asks the other session for the lock that VACUUM, ANALYZE and autovacuum take, and returns the
     * SQLSTATE of its refusal, or null when it was granted. Without waiting, any holder refuses it.
     * Waiting up to five seconds outlasts an autovacuum worker that happens to hold it, since
     * PostgreSQL cancels such a worker after deadlock_timeout; a lock this test leaves held still refuses it.
     */
    private function lockFromTheOtherSession(string $table, bool $wait): ?string
    {
        $this->other->beginTransaction();
        try {
            if ($wait) {
                $this->other->executeStatement("SET LOCAL lock_timeout = '5s'");
            }
            $this->other->executeStatement('LOCK TABLE ' . $table . ' IN SHARE UPDATE EXCLUSIVE MODE' . ($wait ? '' : ' NOWAIT'));

            return null;
        } catch (DriverException $refusal) {
            return $refusal->getSQLState();
        } finally {
            $this->other->rollBack();
        }
    }

    /**
     * The Groonga Sources objects whose relfilenode no relation in pg_class holds: PGroonga's own test of
     * whether an index's objects are still in use. Each index has one, named Sources<relfilenode>.
     *
     * @return list<string>
     */
    private function leftovers(): array
    {
        /** @var list<string> */
        return $this->session->fetchFirstColumn(<<<'SQL'
            SELECT t ->> 1
            FROM jsonb_array_elements(pgroonga_command('table_list')::jsonb -> 1) AS t
            WHERE t ->> 1 ~ '^Sources[0-9]+$'
              AND NOT EXISTS (SELECT FROM pg_class c WHERE c.relfilenode = substr(t ->> 1, 8)::oid)
            ORDER BY 1
            SQL);
    }
}
