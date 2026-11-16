<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Support\Doctrine\PgroongaLeftovers;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A PGroonga index built inside a transaction keeps answering after its commit, although another
 * session ran PGroonga's cleanup while the build was uncommitted. That cleanup runs in every VACUUM
 * and ANALYZE of a table with a PGroonga index, autovacuum's included, and in pgroonga_vacuum(); it
 * removes the Groonga objects of each index it cannot find in the catalog. PGroonga before 4.1.0
 * looked under the cleaning session's snapshot, missed the uncommitted index and deleted its objects,
 * so the index answered "object isn't found" until a REINDEX (upstream GH-1013). A migration that builds
 * a PGroonga index, such as the initial one, and a throwaway test schema that replays every migration in
 * one transaction both build indexes that way.
 *
 * The cleaning session clears leftover objects before the build starts, so its own cleanup has nothing
 * to collide with an autovacuum cleanup over. Clearing after the build started would itself delete the
 * build's objects on an affected PGroonga.
 */
final class PgroongaUncommittedIndexTest extends TestCase
{
    private const string INDEX_OPTIONS = "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'";

    private Connection $builder;
    private Connection $cleaner;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable, migrated PostgreSQL database with PGroonga.');
        }

        $this->schema = 'pgroonga_uncommitted_' . (random_bytes(8) |> bin2hex(...));
        $this->cleaner = ArtistImportIdentityRace::connect($this->schema);
        $this->cleaner->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->cleaner->executeStatement('CREATE TABLE committed_titles (id integer PRIMARY KEY, title text NOT NULL)');
        $this->cleaner->executeStatement('CREATE INDEX idx_committed_titles_title_pgroonga ON committed_titles USING pgroonga (title) WITH (' . self::INDEX_OPTIONS . ')');
        $this->cleaner->executeStatement("INSERT INTO committed_titles (id, title) VALUES (1, 'Committed title')");
        $this->builder = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->builder)) {
            while ($this->builder->isTransactionActive()) {
                $this->builder->rollBack();
            }
            $this->builder->close();
        }
        if (isset($this->cleaner)) {
            $this->cleaner->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->cleaner->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function cleanups(): iterable
    {
        yield 'analyze' => ['ANALYZE committed_titles'];
        yield 'vacuum' => ['VACUUM committed_titles'];
        yield 'pgroonga_vacuum' => ['SELECT pgroonga_vacuum()'];
    }

    #[DataProvider('cleanups')]
    public function testAnIndexBuiltBeforeItsCommitAnswersAfterAnotherSessionsCleanup(string $cleanup): void
    {
        PgroongaLeftovers::clear($this->cleaner);

        // The table is new: building an index on an existing table holds a SHARE lock that VACUUM,
        // ANALYZE and the clearing above would wait for.
        $this->builder->beginTransaction();
        $this->builder->executeStatement('CREATE TABLE fresh_titles (id integer PRIMARY KEY, title text NOT NULL)');
        $this->builder->executeStatement('CREATE INDEX idx_fresh_titles_title_pgroonga ON fresh_titles USING pgroonga (title) WITH (' . self::INDEX_OPTIONS . ')');

        // In autocommit: VACUUM refuses a transaction block.
        $this->cleaner->executeStatement($cleanup);

        $this->builder->commit();
        $this->builder->executeStatement("INSERT INTO fresh_titles (id, title) VALUES (1, 'Fresh title')");
        $this->builder->executeStatement('SET enable_seqscan = off');
        self::assertSame(['Fresh title'], $this->builder->fetchFirstColumn("SELECT title FROM fresh_titles WHERE title &@~ 'fresh'"));
    }
}
