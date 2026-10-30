<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveScope;
use App\Shared\Application\LiveUpdates\LiveScopeKind;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveChangeProjector;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveRevisionStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class RevisionProjectionTest extends TestCase
{
    private Connection $writer;
    private Connection $projector;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->projector = DriverManager::getConnection($params);
        $this->schema = 'live_revision_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->projector] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        $this->writer->executeStatement('CREATE TABLE query_change_inbox (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, family text NOT NULL, scope_kind text NOT NULL, scope_id text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp())');
        $this->writer->executeStatement('CREATE TABLE query_scope_revision (family text NOT NULL, scope_kind text NOT NULL, scope_id text NOT NULL, revision bigint NOT NULL CHECK (revision > 0), PRIMARY KEY (family, scope_kind, scope_id))');
    }

    protected function tearDown(): void
    {
        if (!isset($this->writer)) {
            return;
        }
        foreach ([$this->writer, $this->projector] as $connection) {
            while ($connection->getTransactionNestingLevel() > 0) {
                $connection->rollBack();
            }
        }
        $this->writer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->writer->close();
        $this->projector->close();
    }

    public function testUncommittedAndRolledBackWritesNeverPublish(): void
    {
        $this->writer->beginTransaction();
        $this->append();
        self::assertSame(0, $this->fold());
        self::assertSame([], $this->revisions());
        self::assertNull((new DoctrineLiveRevisionStore($this->projector))->health()->oldestPendingAgeMs);
        $this->writer->rollBack();
        self::assertSame(0, $this->fold());
        self::assertSame([], $this->revisions());
    }

    public function testLateLowerIdentityCommitIsProjectedAfterHigherIdentity(): void
    {
        $this->writer->beginTransaction();
        $this->append();
        $this->projector->executeStatement("INSERT INTO query_change_inbox(family,scope_kind,scope_id) VALUES ('catalog','library','baander.app')");
        self::assertSame(1, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '1'], $this->revisions());
        $this->writer->commit();
        self::assertSame(1, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '2'], $this->revisions());
        self::assertSame(0, $this->fold());
    }

    public function testBoundedBatchCoalescesAndKeepsTombstones(): void
    {
        $this->writer->executeStatement("INSERT INTO query_change_inbox(family,scope_kind,scope_id) SELECT 'catalog','library','baander.app' FROM generate_series(1,1001)");
        self::assertNotNull((new DoctrineLiveRevisionStore($this->projector))->health()->oldestPendingAgeMs);
        self::assertSame(1000, $this->fold());
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM query_change_inbox'));
        self::assertSame(['catalog:library:baander.app' => '1'], $this->revisions());
        self::assertSame(1, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '2'], $this->revisions());
        self::assertSame(0, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '2'], $this->revisions());
    }

    public function testFailureAfterRevisionWriteRollsBackAndRetryProjectsExactlyOnce(): void
    {
        $this->append();
        $this->writer->executeStatement("CREATE FUNCTION reject_inbox_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'simulated crash'; END $$");
        $this->writer->executeStatement('CREATE TRIGGER reject_delete BEFORE DELETE ON query_change_inbox FOR EACH STATEMENT EXECUTE FUNCTION reject_inbox_delete()');
        try {
            $this->fold();
            self::fail('Simulated failure must abort projection.');
        } catch (DriverException) {
            $this->restoreProjectorNamespace();
            self::assertSame([], $this->revisions());
            self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM query_change_inbox'));
        }
        $this->writer->executeStatement('DROP TRIGGER reject_delete ON query_change_inbox');
        self::assertSame(1, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '1'], $this->revisions());
        self::assertSame('0', $this->projector->fetchOne('SHOW statement_timeout'));
        self::assertSame('0', $this->projector->fetchOne('SHOW transaction_timeout'));
    }

    public function testRevisionLockContentionTimesOutAndRetainsInbox(): void
    {
        $this->append();
        $this->fold();
        $this->append();
        $this->writer->beginTransaction();
        $this->writer->executeQuery('SELECT * FROM query_scope_revision FOR UPDATE')->free();
        $oldBackend = $this->projector->fetchOne('SELECT pg_backend_pid()');
        $started = microtime(true);
        try {
            $this->fold();
            self::fail('Locked revision must time out.');
        } catch (DriverException $error) {
            self::assertSame('57014', $error->getSQLState());
            self::assertLessThan(2.0, microtime(true) - $started);
        }
        $this->writer->rollBack();
        self::assertFalse($this->projector->isConnected());
        $this->restoreProjectorNamespace();
        self::assertNotSame($oldBackend, $this->projector->fetchOne('SELECT pg_backend_pid()'));
        self::assertSame(1, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '2'], $this->revisions());
    }

    public function testTransactionDeadlineDiscardsConnectionAndFreshRetryPreservesWork(): void
    {
        $this->append();
        $this->writer->executeStatement("CREATE FUNCTION delay_projection() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.14); RETURN NULL; END $$");
        $this->writer->executeStatement('CREATE TRIGGER slow_delete AFTER DELETE ON query_change_inbox FOR EACH STATEMENT EXECUTE FUNCTION delay_projection()');
        $this->writer->executeStatement('CREATE CONSTRAINT TRIGGER slow_commit AFTER DELETE ON query_change_inbox DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION delay_projection()');
        $oldBackend = $this->projector->fetchOne('SELECT pg_backend_pid()');
        try {
            $this->fold();
            self::fail('The complete transaction must respect its deadline.');
        } catch (DriverException) {
            self::assertFalse($this->projector->isConnected());
        }
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM query_change_inbox'));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM query_scope_revision'));
        $this->writer->executeStatement('DROP TRIGGER slow_delete ON query_change_inbox');
        $this->writer->executeStatement('DROP TRIGGER slow_commit ON query_change_inbox');
        $this->restoreProjectorNamespace();
        self::assertNotSame($oldBackend, $this->projector->fetchOne('SELECT pg_backend_pid()'));
        self::assertSame(1, $this->fold());
        self::assertSame(['catalog:library:baander.app' => '1'], $this->revisions());
    }

    public function testBusySingletonLeavesCommittedRowsUntouched(): void
    {
        $this->append();
        $this->writer->beginTransaction();
        $this->writer->fetchOne("SELECT pg_advisory_xact_lock(hashtextextended('baander.query-change-projector', 0))");
        self::assertSame(0, $this->fold());
        self::assertSame([], $this->revisions());
        self::assertSame(1, (int) $this->projector->fetchOne('SELECT count(*) FROM query_change_inbox'));
        $this->writer->rollBack();
        self::assertSame(1, $this->fold());
    }

    public function testDistinctScopesAdvanceOncePerBatch(): void
    {
        $this->append();
        $this->append();
        $this->writer->executeStatement("INSERT INTO query_change_inbox(family,scope_kind,scope_id) VALUES ('notifications','user','other.baander.app'),('catalog','library','second.baander.app')");
        self::assertSame(4, $this->fold());
        self::assertSame(['1', '1', '1'], $this->projector->fetchFirstColumn('SELECT revision::text FROM query_scope_revision ORDER BY family,scope_kind,scope_id'));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM query_change_inbox'));
    }

    public function testBigintOverflowRetainsCommittedInboxAndRevision(): void
    {
        $this->writer->executeStatement("INSERT INTO query_scope_revision VALUES ('catalog','library','baander.app',9223372036854775807)");
        $this->append();
        try {
            $this->fold();
            self::fail('Counter overflow must preserve pending work.');
        } catch (DriverException $error) {
            self::assertSame('22003', $error->getSQLState());
            $this->restoreProjectorNamespace();
        }
        self::assertSame(['catalog:library:baander.app' => '9223372036854775807'], $this->revisions());
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM query_change_inbox'));
    }

    public function testReaderPreservesBigintStringsAndRestrictsRequestedScopes(): void
    {
        $this->writer->executeStatement("INSERT INTO query_scope_revision VALUES ('catalog','library','baander.app',9223372036854775806),('notifications','user','other.baander.app',10)");
        self::assertSame(['catalog:library:baander.app' => '9223372036854775806'], $this->revisions());
        self::assertSame([], (new DoctrineLiveRevisionStore($this->projector))->revisions([]));
    }

    public function testReadOnlyRepeatableReadKeepsCommittedRevisionSnapshot(): void
    {
        $this->append();
        $this->fold();
        $this->projector->beginTransaction();
        $this->projector->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        self::assertSame(['catalog:library:baander.app' => '1'], $this->revisions());
        $this->append();
        self::assertSame(1, (new DoctrineLiveChangeProjector($this->writer))->project());
        self::assertSame(['catalog:library:baander.app' => '1'], $this->revisions());
        $this->projector->commit();
        self::assertSame(['catalog:library:baander.app' => '2'], $this->revisions());
    }

    public function testLargeScopeSetReadsAcrossBatchesWithinReadOnlySnapshot(): void
    {
        $this->writer->executeStatement("INSERT INTO query_scope_revision SELECT 'catalog','library',n::text || '.baander.app',1 FROM generate_series(1,501) n");
        $scopes = [];
        for ($index = 1; $index <= 501; ++$index) {
            $scopes[] = new LiveScope(LiveFamily::Catalog, LiveScopeKind::Library, $index . '.baander.app');
        }
        $scopes[] = $scopes[0];
        $this->projector->beginTransaction();
        $this->projector->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $revisions = (new DoctrineLiveRevisionStore($this->projector))->revisions($scopes);
        self::assertCount(501, $revisions);
        self::assertSame('1', $revisions['catalog:library:1.baander.app']);
        self::assertSame('1', $revisions['catalog:library:501.baander.app']);
        self::assertSame(1, $this->projector->getTransactionNestingLevel());
        $this->projector->commit();
    }

    public function testProducerTransactionCannotHostProjectionOrObservation(): void
    {
        $this->projector->beginTransaction();
        try {
            $this->fold();
            self::fail('Projection inside a producer transaction must fail.');
        } catch (\LogicException) {
            self::assertSame(1, $this->projector->getTransactionNestingLevel());
        }
        $this->expectException(\LogicException::class);
        $this->revisions();
    }

    private function restoreProjectorNamespace(): void
    {
        $this->projector->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->projector->executeStatement("SET TIME ZONE 'UTC'");
    }

    private function append(): void
    {
        $this->writer->executeStatement("INSERT INTO query_change_inbox(family,scope_kind,scope_id) VALUES ('catalog','library','baander.app')");
    }

    private function fold(): int
    {
        return (new DoctrineLiveChangeProjector($this->projector))->project();
    }

    /** @return array<string,string> */
    private function revisions(): array
    {
        return (new DoctrineLiveRevisionStore($this->projector))->revisions([new LiveScope(LiveFamily::Catalog, LiveScopeKind::Library, 'baander.app')]);
    }
}
