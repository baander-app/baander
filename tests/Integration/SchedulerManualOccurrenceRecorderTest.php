<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceStore;
use App\Shared\Domain\Model\Uuid;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real migrations, independently committed manual captures and retained retry identities. */
#[SkipDatabaseRollback]
final class SchedulerManualOccurrenceRecorderTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    /** @var array<string, mixed> */
    private array $params;
    /** @var list<Connection> */
    private array $extras = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($this->params);
        $this->second = DriverManager::getConnection($this->params);
        $this->schema = 'scheduler_manual_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version_60000000_20260619CreateMissingEntityTables.php';
        $jobs = new \DoctrineMigrations\Version620260619CreateMissingEntityTables($this->first, new NullLogger());
        $jobs->up(new Schema());
        foreach ($jobs->getSql() as $query) {
            if (str_contains($query->getStatement(), 'scheduled_jobs')) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002230000.php';
        $occurrences = new \DoctrineMigrations\Version20261002230000($this->first, new NullLogger());
        $occurrences->up(new Schema());
        foreach ($occurrences->getSql() as $query) {
            $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    public function testDistinctManualRequestsCaptureDatabaseUtcMinuteAndExactOrderedSnapshot(): void
    {
        $parameters = ['address' => 'scheduler@baander.app', 'largeFloat' => 1.0e18, 'negativeZero' => -0.0, 'fraction' => 1.0, 'integer' => 1, 'nested' => [false, null, ['01' => '日本語']], 3 => 'third', 1 => 'first'];
        $job = $this->job(parameters: $parameters);
        $state = $this->jobRow($job);
        $this->first->executeStatement("SET TIME ZONE 'Pacific/Auckland'");
        $before = $this->databaseMinute();
        $first = $this->recorder()->record($job, Uuid::v7());
        $second = $this->recorder()->record($job, Uuid::v7());
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first->id->equals($second->id));
        foreach ([$first, $second] as $capture) {
            self::assertSame(SchedulerOccurrenceOrigin::Manual, $capture->origin);
            self::assertTrue($capture->jobId->equals($job));
            self::assertSame(JobType::Console, $capture->jobType);
            self::assertSame('app:manual-fixture', $capture->command);
            self::assertSame($parameters, $capture->parameters);
            self::assertSame(json_encode($parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $capture->parametersJson());
            self::assertSame('UTC', $capture->scheduledFor->getTimezone()->getName());
            self::assertSame('00.000000', $capture->scheduledFor->format('s.u'));
            self::assertGreaterThanOrEqual($before->getTimestamp(), $capture->scheduledFor->getTimestamp());
            self::assertLessThanOrEqual($this->databaseMinute()->getTimestamp(), $capture->scheduledFor->getTimestamp());
            $found = (new DoctrineSchedulerOccurrenceStore($this->second))->findById($capture->id);
            self::assertNotNull($found, 'A separate connection sees the committed capture before return.');
            self::assertEquals($capture, $found);
        }
        // If wall time crossed a minute, both captures still establish separate manual request identities.
        if ($before == $this->databaseMinute()) {
            self::assertEquals($first->scheduledFor, $second->scheduledFor);
        }
        self::assertSame(2, $this->countOccurrences());
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertSame($state, $this->jobRow($job));
        self::assertSame('0', $this->first->fetchOne('SHOW lock_timeout'));
        self::assertSame('0', $this->first->fetchOne('SHOW statement_timeout'));
    }

    /** @return iterable<string, array{string}> */
    public static function statuses(): iterable
    {
        foreach (['active', 'paused', 'disabled'] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('statuses')]
    public function testManualCaptureIgnoresCronAndStatusWithoutChangingSchedule(string $status): void
    {
        $job = $this->job($status);
        $this->second->executeStatement("UPDATE scheduled_jobs SET expression = 'invalid cron', evaluated_through = date_trunc('minute', clock_timestamp(), 'UTC') - INTERVAL '1 day' WHERE id = :id", ['id' => $job->toString()]);
        $before = $this->jobRow($job);
        self::assertNotNull($this->recorder()->record($job, Uuid::v7()));
        self::assertSame($before, $this->jobRow($job));
    }

    public function testMissingJobReturnsNullWithoutCreatingIntent(): void
    {
        self::assertNull($this->recorder()->record(Uuid::v7(), Uuid::v7()));
        self::assertSame(0, $this->countOccurrences());
        self::assertSame(0, $this->first->getTransactionNestingLevel());
    }

    public function testRetryReturnsOriginalSnapshotAndMinuteAfterEditAndDeletion(): void
    {
        $job = $this->job();
        $request = Uuid::v7();
        $original = $this->recorder()->record($job, $request);
        self::assertNotNull($original);
        // Advance retained time away from the current DB minute without waiting for a clock boundary.
        $this->second->executeStatement("UPDATE scheduler_occurrences SET scheduled_for = scheduled_for - INTERVAL '1 day' WHERE id = :id", ['id' => $request->toString()]);
        $original = (new DoctrineSchedulerOccurrenceStore($this->second))->findById($request);
        self::assertNotNull($original);
        $this->second->executeStatement("UPDATE scheduled_jobs SET command = 'app:changed-fixture', job_type = 'messenger', parameters = '{\"different\":2.0}'::json, status = 'disabled', revision = :revision WHERE id = :id", ['id' => $job->toString(), 'revision' => Uuid::v7()->toString()]);
        self::assertEquals($original, $this->recorder()->record($job, $request));
        $this->second->executeStatement('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => $job->toString()]);
        self::assertEquals($original, $this->recorder()->record($job, $request));
        self::assertSame(1, $this->countOccurrences());
        self::assertNull($this->recorder()->record($job, Uuid::v7()));
    }

    /** @return iterable<string, array{bool}> */
    public static function identityConflicts(): iterable
    {
        yield 'manual ID for another job' => [false];
        yield 'scheduled ID for same job' => [true];
    }

    #[DataProvider('identityConflicts')]
    public function testExistingRequestIdCannotChangeJobOrOrigin(bool $scheduled): void
    {
        $job = $this->job();
        $other = $this->job();
        $request = Uuid::v7();
        if ($scheduled) {
            $existing = new SchedulerOccurrence($request, $job, $this->databaseMinute(), JobType::Console, 'app:scheduled-fixture', []);
            self::assertTrue((new DoctrineSchedulerOccurrenceStore($this->second))->record($existing));
        } else {
            self::assertNotNull((new DoctrineSchedulerManualOccurrenceRecorder($this->second))->record($other, $request));
        }
        try {
            $this->recorder()->record($job, $request);
            self::fail('An existing ID with different job/origin must fail closed.');
        } catch (\LogicException) {
            self::assertFalse($this->first->isConnected());
        }
        self::assertSame(1, $this->countOccurrences());
    }

    public function testJobRowLockTimeoutRollsBackClosesConnectionAndPermitsFreshRetry(): void
    {
        $job = $this->job();
        $request = Uuid::v7();
        $before = $this->jobRow($job);
        $this->second->beginTransaction();
        try {
            $this->second->fetchOne('SELECT id FROM scheduled_jobs WHERE id = :id FOR UPDATE', ['id' => $job->toString()]);
            $this->second->executeStatement("UPDATE scheduled_jobs SET command = 'app:uncommitted-fixture' WHERE id = :id", ['id' => $job->toString()]);
            try {
                (new DoctrineSchedulerManualOccurrenceRecorder($this->first, 500, 50))->record($job, $request);
                self::fail('Manual capture must wait for committed schedule state and time out.');
            } catch (DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
            }
            self::assertFalse($this->first->isConnected());
            self::assertSame(0, $this->countOccurrences());
        } finally {
            $this->second->rollBack();
        }
        self::assertSame($before, $this->jobRow($job));
        $retry = (new DoctrineSchedulerManualOccurrenceRecorder($this->second))->record($job, $request);
        self::assertNotNull($retry);
        self::assertSame('app:manual-fixture', $retry->command);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['command', 'type', 'parameters', 'scalar', 'depth'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPersistedPayloadRollsBackBeforeAnyAcknowledgment(string $fault): void
    {
        $job = $this->job();
        if ($fault === 'command') {
            $this->second->executeStatement('UPDATE scheduled_jobs SET command = :value WHERE id = :id', ['value' => str_repeat('x', 513), 'id' => $job->toString()]);
        } elseif ($fault === 'type') {
            $this->second->executeStatement("UPDATE scheduled_jobs SET job_type = 'unsupported' WHERE id = :id", ['id' => $job->toString()]);
        } else {
            $value = $fault === 'scalar' ? 'false' : json_encode(['large' => str_repeat('x', 16385)], JSON_THROW_ON_ERROR);
            if ($fault === 'depth') {
                $nested = 'too deep';
                for ($depth = 0; $depth < 9; ++$depth) {
                    $nested = ['nested' => $nested];
                }
                $value = json_encode($nested, JSON_THROW_ON_ERROR);
            }
            $this->second->executeStatement('UPDATE scheduled_jobs SET parameters = CAST(:value AS JSON) WHERE id = :id', ['value' => $value, 'id' => $job->toString()]);
        }
        $before = $this->jobRow($job);
        try {
            $this->recorder()->record($job, Uuid::v7());
            self::fail('Unsupported persisted invocation must not be acknowledged.');
        } catch (\InvalidArgumentException|\UnexpectedValueException $error) {
            self::assertNotSame('', $error->getMessage());
            self::assertFalse($this->first->isConnected());
        }
        self::assertSame(0, $this->countOccurrences());
        self::assertSame($before, $this->jobRow($job));
    }

    public function testAmbientTransactionCannotAcknowledgeIndependentCommit(): void
    {
        $job = $this->job();
        $this->first->beginTransaction();
        try {
            $this->recorder()->record($job, Uuid::v7());
            self::fail('Manual recorder must reject an ambient transaction.');
        } catch (\LogicException) {
            self::assertSame(0, $this->countOccurrences());
        } finally {
            if ($this->first->isTransactionActive()) {
                $this->first->rollBack();
            }
        }
    }

    public function testRecorderOverridesIsolationOnlyWithinItsOwnTransaction(): void
    {
        $job = $this->job();
        $this->first->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        self::assertNotNull($this->recorder()->record($job, Uuid::v7()));
        self::assertSame('repeatable read', $this->first->fetchOne('SHOW default_transaction_isolation'));
        self::assertSame('repeatable read', $this->first->fetchOne('SHOW transaction_isolation'));
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertSame(1, $this->countOccurrences());
    }

    public function testDisabledAutocommitConnectionCannotAcknowledgeCapture(): void
    {
        $job = $this->job();
        $this->first->setAutoCommit(false);
        try {
            $this->recorder()->record($job, Uuid::v7());
            self::fail('A recorder cannot acknowledge on a connection with disabled autocommit.');
        } catch (\LogicException) {
            self::assertSame(0, $this->countOccurrences());
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function commitFailures(): iterable
    {
        yield 'before commit' => [false];
        yield 'lost commit acknowledgment' => [true];
    }

    #[DataProvider('commitFailures')]
    public function testUncertainCommitNeverReturnsSuccessAndFreshRetryReconcilesSameIdentity(bool $afterCommit): void
    {
        $job = $this->job();
        $request = Uuid::v7();
        $connection = new class($this->params, $this->first->getDriver()) extends Connection {
            public bool $afterCommit = false;

            public function commit(): void
            {
                if ($this->afterCommit) {
                    parent::commit();
                }
                throw new \RuntimeException('Fixture manual commit acknowledgment failed.');
            }
        };
        $this->extras[] = $connection;
        $connection->executeStatement('SET search_path TO ' . $this->schema);
        $connection->afterCommit = $afterCommit;
        try {
            (new DoctrineSchedulerManualOccurrenceRecorder($connection))->record($job, $request);
            self::fail('An uncertain commit must not return an occurrence.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fixture manual commit acknowledgment failed.', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
        self::assertSame($afterCommit ? 1 : 0, $this->countOccurrences());
        $original = (new DoctrineSchedulerOccurrenceStore($this->second))->findById($request);
        $retry = (new DoctrineSchedulerManualOccurrenceRecorder($this->second))->record($job, $request);
        self::assertNotNull($retry);
        self::assertTrue($retry->id->equals($request));
        if ($afterCommit) {
            self::assertEquals($original, $retry);
        }
        self::assertSame(1, $this->countOccurrences());
    }

    public function testConcurrentIndependentProcessesReturnTheSameCommittedCapture(): void
    {
        $job = $this->job();
        $request = Uuid::v7();
        $gate = sys_get_temp_dir() . '/baander-manual-record-' . bin2hex(random_bytes(12));
        $code = <<<'CHILD'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2]);
file_put_contents($argv[3] . '.ready', 'ready');
$deadline = microtime(true) + 5;
while (!is_file($argv[4])) { if (microtime(true) > $deadline) { exit(2); } usleep(1000); }
$recorder = new \App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder($connection, 2000, 1000);
$occurrence = $recorder->record(\App\Shared\Domain\Model\Uuid::fromString($argv[5]), \App\Shared\Domain\Model\Uuid::fromString($argv[6]));
echo json_encode([$occurrence->id->toString(), $occurrence->scheduledFor->format('c'), $occurrence->command, $occurrence->parametersJson()], JSON_THROW_ON_ERROR);
CHILD;
        $processes = [];
        $outputs = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $output = tmpfile();
                self::assertIsResource($output);
                $outputs[] = $output;
                $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $gate . $i, $gate, $job->toString(), $request->toString()], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
                self::assertIsResource($process);
                $processes[] = $process;
            }
            $deadline = microtime(true) + 5;
            while (!is_file($gate . '0.ready') || !is_file($gate . '1.ready')) {
                if (microtime(true) > $deadline) {
                    self::fail('Both independent manual recording contenders must become ready.');
                }
                usleep(1000);
            }
            file_put_contents($gate, 'start');
            $results = [];
            foreach ($processes as $index => $process) {
                while (proc_get_status($process)['running']) {
                    if (microtime(true) > $deadline) {
                        self::fail('Concurrent manual capture exceeded the fixture deadline.');
                    }
                    usleep(1000);
                }
                rewind($outputs[$index]);
                $output = stream_get_contents($outputs[$index]);
                self::assertSame(0, proc_close($process), $output);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertSame($results[0], $results[1]);
            self::assertSame($request->toString(), $results[0][0]);
            self::assertSame(1, $this->countOccurrences());
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process, 9);
                    proc_close($process);
                }
            }
            foreach ($outputs as $output) {
                fclose($output);
            }
            foreach ([$gate, $gate . '0.ready', $gate . '1.ready'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testWaitingRetryReturnsCommittedIdentityEvenWhenLockHolderDeletesJob(): void
    {
        $job = $this->job();
        $request = Uuid::v7();
        $winner = new SchedulerOccurrence($request, $job, $this->databaseMinute()->modify('-1 day'), JobType::Console,
            'app:winning-manual-fixture', ['z' => 1.0, 'a' => -0.0], SchedulerOccurrenceOrigin::Manual);
        $application = 'manual_retry_' . bin2hex(random_bytes(8));
        $code = <<<'CHILD'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2]);
$connection->executeQuery("SELECT set_config('application_name', :application, false)", ['application' => $argv[3]])->free();
$connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$occurrence = (new \App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder($connection, 5000, 4000))
    ->record(\App\Shared\Domain\Model\Uuid::fromString($argv[4]), \App\Shared\Domain\Model\Uuid::fromString($argv[5]));
echo json_encode($occurrence === null ? null : [$occurrence->id->toString(), $occurrence->scheduledFor->format('c'), $occurrence->command, $occurrence->parametersJson()], JSON_THROW_ON_ERROR);
CHILD;
        $output = tmpfile();
        self::assertIsResource($output);
        $process = null;
        $this->second->beginTransaction();
        try {
            $this->second->fetchOne('SELECT id FROM scheduled_jobs WHERE id = :id FOR UPDATE', ['id' => $job->toString()]);
            $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $application, $job->toString(), $request->toString()], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
            self::assertIsResource($process);
            $deadline = microtime(true) + 3;
            while (true) {
                $this->second->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
                $waiting = (int) $this->second->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = :application AND wait_event_type = 'Lock'", ['application' => $application]);
                if ($waiting === 1) {
                    break;
                }
                if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                    rewind($output);
                    self::fail('Retry must reach the actual job-row lock before the winner commits: ' . stream_get_contents($output));
                }
                usleep(1000);
            }
            // The winning transaction retains its identity while removing the mutable schedule.
            $this->second->executeStatement('INSERT INTO scheduler_occurrences (id, job_id, scheduled_for, job_type, command, parameters, origin) VALUES (:id, :job, :at, :type, :command, CAST(:parameters AS JSON), :origin)',
                ['id' => $winner->id->toString(), 'job' => $job->toString(), 'at' => $winner->scheduledFor->format('c'), 'type' => $winner->jobType->value,
                    'command' => $winner->command, 'parameters' => $winner->parametersJson(), 'origin' => $winner->origin->value]);
            $this->second->executeStatement('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => $job->toString()]);
            $this->second->commit();
            $deadline = microtime(true) + 3;
            while (proc_get_status($process)['running']) {
                if (microtime(true) > $deadline) {
                    self::fail('Waiting retry must finish after the winning commit.');
                }
                usleep(1000);
            }
            rewind($output);
            $result = stream_get_contents($output);
            self::assertSame(0, proc_close($process), $result);
            self::assertSame([$winner->id->toString(), $winner->scheduledFor->format('c'), $winner->command, $winner->parametersJson()], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(1, $this->countOccurrences());
            self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM scheduled_jobs WHERE id = :id', ['id' => $job->toString()]));
        } finally {
            if ($this->second->isTransactionActive()) {
                $this->second->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($output);
        }
    }

    public function testDsnFactoryAndContainerResolveDedicatedAutocommitConnection(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertIsString($url);
        $factory = DoctrineSchedulerManualOccurrenceRecorder::fromDsn($url);
        $property = new \ReflectionProperty(DoctrineSchedulerManualOccurrenceRecorder::class, 'connection');
        $factoryConnection = $property->getValue($factory);
        self::assertInstanceOf(Connection::class, $factoryConnection);
        $this->extras[] = $factoryConnection;
        self::assertTrue($factoryConnection->isAutoCommit());
        self::assertSame(0, $factoryConnection->getTransactionNestingLevel());
        self::assertNotSame($this->first, $factoryConnection);
        $kernel = new Kernel('test', false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $resolved = $container->get('test.scheduler_manual_recorder');
            self::assertInstanceOf(DoctrineSchedulerManualOccurrenceRecorder::class, $resolved);
            $dedicated = $property->getValue($resolved);
            self::assertInstanceOf(Connection::class, $dedicated);
            $this->extras[] = $dedicated;
            $shared = $container->get('doctrine')->getConnection();
            self::assertNotSame($shared, $dedicated);
            self::assertTrue($dedicated->isAutoCommit());
            self::assertSame(0, $dedicated->getTransactionNestingLevel());
            self::assertSame($this->second->fetchOne('SELECT current_database()'), $dedicated->fetchOne('SELECT current_database()'));
            $dedicated->executeStatement('SET search_path TO ' . $this->schema);
            $job = $this->job();
            $shared->beginTransaction();
            try {
                $recorded = $resolved->record($job, Uuid::v7());
                self::assertNotNull($recorded);
                self::assertSame(1, $this->countOccurrences());
                self::assertSame(1, $shared->getTransactionNestingLevel());
            } finally {
                $shared->rollBack();
            }
            self::assertSame(1, $this->countOccurrences());
        } finally {
            $kernel->shutdown();
        }
    }

    private function recorder(): DoctrineSchedulerManualOccurrenceRecorder
    {
        return new DoctrineSchedulerManualOccurrenceRecorder($this->first);
    }

    /** @param array<array-key, mixed> $parameters */
    private function job(string $status = 'active', array $parameters = ['z' => 1.0, 'a' => -0.0]): Uuid
    {
        $id = Uuid::v7();
        $this->second->executeStatement("INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, parameters, created_at, updated_at, next_run_at, run_count) VALUES (:id, :revision, :name, '* * * * *', 'console', 'app:manual-fixture', :status, CAST(:parameters AS JSON), clock_timestamp(), clock_timestamp(), clock_timestamp() + INTERVAL '1 hour', 7)", ['id' => $id->toString(), 'revision' => Uuid::v7()->toString(), 'name' => 'Manual ' . $id->toString(), 'status' => $status, 'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
        return $id;
    }

    /** @return array<string, mixed> */
    private function jobRow(Uuid $job): array
    {
        $row = $this->second->fetchAssociative('SELECT * FROM scheduled_jobs WHERE id = :id', ['id' => $job->toString()]);
        self::assertIsArray($row);
        return $row;
    }

    private function databaseMinute(): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->second->fetchOne("SELECT date_trunc('minute', clock_timestamp(), 'UTC')::text")))->setTimezone(new DateTimeZone('UTC'));
    }

    private function countOccurrences(): int
    {
        return (int) $this->second->fetchOne('SELECT count(*) FROM scheduler_occurrences');
    }

    protected function tearDown(): void
    {
        foreach (array_merge(isset($this->first) ? [$this->first] : [], isset($this->second) ? [$this->second] : [], $this->extras) as $connection) {
            $connection->close();
        }
        if (isset($this->schema)) {
            $cleanup = DriverManager::getConnection($this->params);
            try {
                $cleanup->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            } finally {
                $cleanup->close();
            }
        }
    }
}
