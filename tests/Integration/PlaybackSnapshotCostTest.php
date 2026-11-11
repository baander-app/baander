<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Application\Service\PlaybackCoordinator;
use App\Tests\Fixtures\Session\PlaybackCostFixture;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Fixtures\Session\PlaybackStatementLog;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-snapshot-cost.php';

/**
 * The relay builds a snapshot once per second for every bound socket, so each statement on that
 * path is paid per player per second. The ceilings are the counts measured for the current store:
 * a statement added to either path fails here.
 */
final class PlaybackSnapshotCostTest extends TestCase
{
    // The SQL store used 9, 12 and 12. The session row is now inserted only when the locked read
    // finds none, and the selected and the active destination are found among the destinations the
    // snapshot reads anyway, which saves three statements on every snapshot. The snapshot that first
    // shows a command to its runner pays one more statement for the mark.
    private const int UNBOUND_PLAYING_SNAPSHOT = 6;
    private const int BOUND_RESTING_SNAPSHOT = 9;
    private const int BOUND_FIRST_DISCLOSURE_SNAPSHOT = 10;
    // An addressed command is inserted through the unit of work: one read carries the
    // transaction clock and the replay-key check, a savepoint wraps the insert, and the
    // control's own answer marks the new command as shown. The SQL store used 19.
    private const int CONTROL_ISSUING_ONE_COMMAND = 21;

    private string $schema;
    private Connection $connection;
    private EntityManager $manager;
    private PlaybackCoordinator $service;
    private PlaybackStatementLog $log;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schema = 'playback_cost_' . bin2hex(random_bytes(8));
        $this->log = new PlaybackStatementLog();
        $this->connection = PlaybackCostFixture::countingConnection('public', $this->log);
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->manager = PlaybackPersistenceFixture::manager($this->connection);
        $this->service = PlaybackPersistenceFixture::coordinator($this->manager);
    }

    protected function tearDown(): void
    {
        if (!isset($this->manager)) {
            return;
        }
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->manager->close();
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->close();
    }

    public function testASnapshotForAnUnboundSocketWhileTheRunnerPlaysStaysWithinItsStatements(): void
    {
        ['owner' => $owner, 'runner' => $runner] = PlaybackCostFixture::playingRunner($this->service, $this->manager);
        $snapshot = null;

        $statements = $this->log->during(function () use ($owner, &$snapshot): void {
            $snapshot = PlaybackCostFixture::unboundSnapshot($this->service, $owner);
        });

        self::assertSame('playing', $snapshot['observedState']);
        self::assertSame($runner['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame([], $snapshot['commands']);
        $this->assertWithin(self::UNBOUND_PLAYING_SNAPSHOT, $statements);
    }

    public function testASnapshotForABoundSocketWhileTheRunnerRestsPausedStaysWithinItsStatements(): void
    {
        ['owner' => $owner, 'runner' => $runner, 'snapshot' => $paused] = PlaybackCostFixture::pausedRunner($this->service, $this->manager);
        $snapshot = null;

        $statements = $this->log->during(function () use ($owner, $runner, &$snapshot): void {
            $snapshot = PlaybackCostFixture::boundSnapshot($this->service, $owner, $runner);
        });

        self::assertSame('paused', $snapshot['desiredState']);
        self::assertSame('paused', $snapshot['observedState']);
        self::assertSame($runner['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame($paused['revision'], $snapshot['revision']);
        self::assertSame([], $snapshot['commands']);
        $this->assertWithin(self::BOUND_RESTING_SNAPSHOT, $statements);
        // The same snapshot again costs the same: nothing is left behind that a later one pays for.
        self::assertCount(count($statements), $this->log->during(fn(): array => PlaybackCostFixture::boundSnapshot($this->service, $owner, $runner)));
    }

    public function testTheSnapshotThatFirstShowsACommandToItsRunnerStaysWithinItsStatements(): void
    {
        ['owner' => $owner, 'runner' => $runner, 'snapshot' => $paused] = PlaybackCostFixture::pausedRunner($this->service, $this->manager);
        $other = PlaybackCostFixture::endpoint($this->service, $this->manager, $owner);
        // Another tab asks the resting runner to pause; the runner has not been shown the command yet.
        $issued = PlaybackCostFixture::control($this->service, $owner, $other, 'pause', $paused['revision']);
        $commandId = $issued['requestResult']['commandId'];
        self::assertSame([], $issued['commands']);
        self::assertNull($this->connection->fetchOne('SELECT disclosed_at FROM playback_commands WHERE id=?', [$commandId]));
        $snapshot = null;

        $statements = $this->log->during(function () use ($owner, $runner, &$snapshot): void {
            $snapshot = PlaybackCostFixture::boundSnapshot($this->service, $owner, $runner);
        });

        self::assertSame([$commandId], array_column($snapshot['commands'], 'id'));
        self::assertNotNull($this->connection->fetchOne('SELECT disclosed_at FROM playback_commands WHERE id=?', [$commandId]));
        $this->assertWithin(self::BOUND_FIRST_DISCLOSURE_SNAPSHOT, $statements);
    }

    public function testAControlThatIssuesOneCommandToTheRunnerStaysWithinItsStatements(): void
    {
        ['owner' => $owner, 'runner' => $runner, 'snapshot' => $paused] = PlaybackCostFixture::pausedRunner($this->service, $this->manager);
        $issued = null;

        $statements = $this->log->during(function () use ($owner, $runner, $paused, &$issued): void {
            $issued = PlaybackCostFixture::control($this->service, $owner, $runner, 'pause', $paused['revision']);
        });

        self::assertSame(['pause'], array_column($issued['commands'], 'type'));
        self::assertSame($issued['requestResult']['commandId'], $issued['commands'][0]['id']);
        self::assertNull($issued['requestResult']['outcome']);
        self::assertSame($paused['revision'] + 1, $issued['revision']);
        $this->assertWithin(self::CONTROL_ISSUING_ONE_COMMAND, $statements);
    }

    public function testServingManyAccountsInTurnThroughOneEntityManagerCostsEachSnapshotTheSame(): void
    {
        $accounts = array_map(fn(): array => PlaybackCostFixture::pausedRunner($this->service, $this->manager), range(1, 50));
        // Setting up fifty accounts takes longer than a registration lasts, and a lapsed resting
        // runner would be released by its first snapshot.
        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP+INTERVAL '1 day'");
        $this->manager->clear();
        $serve = fn(array $account): array => $this->log->during(fn(): array => PlaybackCostFixture::boundSnapshot($this->service, $account['owner'], $account['runner']));

        $alone = $serve($accounts[0]);
        $this->assertWithin(self::BOUND_RESTING_SNAPSHOT, $alone);
        foreach ([1, 2] as $tick) {
            foreach ($accounts as $number => $account) {
                self::assertCount(count($alone), $serve($account), "Tick {$tick}, account {$number}.");
            }
            // Nothing the relay served stays managed, so the next tick's flushes have nothing to walk.
            self::assertSame(0, $this->manager->getUnitOfWork()->size(), "After tick {$tick}.");
        }
        self::assertSame(
            50,
            (int) $this->connection->fetchOne('SELECT count(*) FROM listening_sessions WHERE active_incarnation_id IS NOT NULL'),
            'Every served runner still holds its session.',
        );
    }

    /** @param list<string> $statements */
    private function assertWithin(int $ceiling, array $statements): void
    {
        self::assertLessThanOrEqual($ceiling, count($statements), "The operation sent these statements:\n" . implode("\n", array_map(
            static fn(string $statement): string => '  ' . preg_replace('/\s+/', ' ', trim($statement)),
            $statements,
        )));
    }
}
