<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Scheduler\Infrastructure\Process\SchedulerWorkerRunner;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection as RedisConnection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;

/** Actual isolated child, PostgreSQL and Redis; never consumes or executes the published jobs. */
final class SchedulerWorkerRunnerTest extends TestCase
{
    private Connection $database;
    private string $schema;
    private RedisConnection $redis;
    private RedisTransport $transport;
    private ?WorkerChildProcess $child = null;
    private ?string $barrierDirectory = null;
    /** @var resource */
    private mixed $stdout;
    /** @var resource */
    private mixed $stderr;
    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        $redis = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$url || !$redis) {
            self::markTestSkipped('Use the disposable PostgreSQL/Redis functional runner.');
        }
        $this->database = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'scheduler_loop_' . bin2hex(random_bytes(8));
        $this->database->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->database->executeStatement('SET search_path TO ' . $this->schema);
        require_once dirname(__DIR__, 2) . '/migrations/Version_60000000_20260619CreateMissingEntityTables.php';
        $base = new \DoctrineMigrations\Version620260619CreateMissingEntityTables($this->database, new NullLogger());
        $base->up(new Schema());
        foreach ($base->getSql() as $query) {
            if (str_contains($query->getStatement(), 'scheduled_jobs')) {
                $this->database->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        foreach (['Version20261002210000', 'Version20261002230000', 'Version20261003010000', 'Version20261003020000'] as $version) {
            require_once dirname(__DIR__, 2) . '/migrations/' . $version . '.php';
            $class = 'DoctrineMigrations\\' . $version;
            $migration = new $class($this->database, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->database->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        $lease = (new DoctrineDeploymentLease($this->database))->acquire('scheduler.baander.app', str_repeat('a', 32), 300);
        self::assertNotNull($lease);
        $stream = 'scheduler_loop_' . bin2hex(random_bytes(8));
        $this->redis = RedisConnection::fromDsn($redis, ['stream' => $stream, 'group' => 'test', 'consumer' => 'test']);
        $this->transport = new RedisTransport($this->redis, new JsonTransportSerializer(new JsonMessageCodec()));
        $this->transport->setup();
        $this->environment = [...getenv(), 'DATABASE_URL' => $url, 'PGOPTIONS' => '-c search_path=' . $this->schema,
            'PGAPPNAME' => $this->schema, 'BAANDER_TEST_SCHEDULER_STREAM' => $stream,
            'BAANDER_WORKER_NAMESPACE' => $lease->namespace, 'BAANDER_WORKER_BOOT_ID' => $lease->bootId,
            'BAANDER_WORKER_LEASE_EPOCH' => (string) $lease->epoch, 'BAANDER_WORKER_ID' => 'scheduler'];
        $this->stdout = tmpfile();
        $this->stderr = tmpfile();
        self::assertIsResource($this->stdout);
        self::assertIsResource($this->stderr);
    }

    public function testRealLoopPublishesHealthyIntentDespitePoisonAndStopsOnTerm(): void
    {
        $poison = $this->seed('invalid cron');
        $healthy = $this->seed();
        $this->start();
        $this->await(fn (): bool => (int) $this->database->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE dispatched_at IS NOT NULL') === 1);
        self::assertSame(1, $this->transport->getMessageCount());
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM scheduled_jobs WHERE id = :id AND recovery_after > clock_timestamp()', ['id' => $poison]));
        $deliveries = iterator_to_array($this->transport->get());
        self::assertCount(1, $deliveries);
        $envelope = reset($deliveries);
        self::assertInstanceOf(Envelope::class, $envelope);
        $message = $envelope->getMessage();
        self::assertInstanceOf(ExecuteScheduledOccurrenceCommand::class, $message);
        self::assertSame($healthy, $this->database->fetchOne('SELECT job_id FROM scheduler_occurrences WHERE id = :id', ['id' => $message->occurrenceId->toString()]));
        $this->transport->ack($envelope);
        $this->child?->requestStop(hrtime(true) / 1e9, 2.0);
        $this->finish(0);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT count(*) FROM scheduler_occurrence_executions'));
        self::assertSame('active', $this->database->fetchOne('SELECT state FROM worker_deployment_leases'), 'Stopping a child does not release deployment ownership.');
    }

    public function testWrongBootFailsBeforeAnyRecoveryOrPublication(): void
    {
        $this->seed();
        $this->start(['BAANDER_WORKER_BOOT_ID' => str_repeat('b', 32)]);
        $this->finish(1);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT count(*) FROM scheduler_occurrences'));
        self::assertSame(0, $this->transport->getMessageCount());
        self::assertStringContainsString('scheduler_worker_failed', $this->diagnostic($this->stderr));
    }

    public function testAuthorityLossStopsBeforeRecoveringNewlyPendingJob(): void
    {
        $this->seed();
        $this->barrierDirectory = sys_get_temp_dir() . '/baander-scheduler-barrier-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->barrierDirectory, 0700));
        $this->start(['BAANDER_TEST_SCHEDULER_BARRIER_DIRECTORY' => $this->barrierDirectory]);
        $this->await(fn (): bool => is_file($this->barrierDirectory . '/waiting'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE dispatched_at IS NOT NULL'));
        self::assertSame(1, $this->transport->getMessageCount());
        $this->database->beginTransaction();
        $this->database->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second'");
        $late = $this->seed();
        $this->database->commit();
        self::assertTrue(touch($this->barrierDirectory . '/release'));
        $this->finish(1);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE job_id = :id', ['id' => $late]));
        self::assertSame(1, $this->transport->getMessageCount());
        self::assertSame('active', $this->database->fetchOne('SELECT state FROM worker_deployment_leases'));
    }

    public function testTermDuringBlockedRecoveryPreventsFollowingRelayPhase(): void
    {
        $job = $this->seed();
        $this->database->executeStatement(<<<'SQL'
            INSERT INTO scheduler_occurrences (id, job_id, scheduled_for, origin, job_type, command, parameters)
            VALUES (:id, :job, date_trunc('minute', clock_timestamp(), 'UTC'), 'manual', 'console', 'app:fixture.baander.app', '[]')
            SQL, ['id' => Uuid::v7()->toString(), 'job' => $job]);
        $this->database->beginTransaction();
        $this->database->executeStatement('LOCK TABLE scheduled_jobs IN ACCESS EXCLUSIVE MODE');
        try {
            $this->start();
            $this->await(function (): bool {
                $this->database->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
                return $this->database->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock' AND query LIKE '%scheduled_jobs%'", ['name' => $this->schema]) !== false;
            });
            $this->child?->requestStop(hrtime(true) / 1e9, 2.0);
            $this->finish(0);
        } finally {
            $this->database->rollBack();
        }
        self::assertSame(0, $this->transport->getMessageCount(), 'TERM during recovery must prevent subsequent relay admission.');
        self::assertSame(0, (int) $this->database->fetchOne('SELECT count(*) FROM scheduler_occurrences WHERE dispatched_at IS NOT NULL'));
    }

    public function testConfiguredKernelResolvesRunnerWithoutStartingIt(): void
    {
        $kernel = new Kernel('test', false);
        try {
            $kernel->boot();
            self::assertInstanceOf(SchedulerWorkerRunner::class, $kernel->getContainer()->get('test.scheduler_worker_runner'));
            self::assertSame(0, $this->transport->getMessageCount());
        } finally {
            $kernel->shutdown();
        }
    }

    private function seed(string $expression = '* * * * *'): string
    {
        $id = Uuid::v7()->toString();
        $this->database->executeStatement(<<<'SQL'
            INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, created_at, updated_at, evaluated_through)
            VALUES (:id, :revision, 'Isolated worker fixture', :expression, 'console', 'app:fixture.baander.app', clock_timestamp(), clock_timestamp(), date_trunc('minute', clock_timestamp(), 'UTC') - INTERVAL '1 minute')
            SQL, ['id' => $id, 'revision' => Uuid::v7()->toString(), 'expression' => $expression]);
        return $id;
    }

    /** @param array<string, string> $overrides */
    private function start(array $overrides = []): void
    {
        $root = dirname(__DIR__, 2);
        $this->child = WorkerChildProcess::start([PHP_BINARY, '-d', 'memory_limit=128M', $root . '/tests/Fixtures/Scheduler/worker-runner.php'], $root,
            $this->stdout, $this->stderr, array_replace($this->environment, $overrides));
    }

    private function finish(int $expected): void
    {
        $child = $this->child;
        self::assertInstanceOf(WorkerChildProcess::class, $child);
        $this->await(fn (): bool => !$child->poll(hrtime(true) / 1e9));
        self::assertSame($expected, $child->exitCode(), 'Signal=' . ($child->terminationSignal() ?? 'none') . '; stdout=' . $this->diagnostic($this->stdout) . '; stderr=' . $this->diagnostic($this->stderr));
        self::assertNull($child->terminationSignal(), 'The runtime must return normally, not rely on a forced signal exit.');
        $this->child = null;
    }

    /** @param \Closure(): bool $condition */
    private function await(\Closure $condition): void
    {
        $deadline = hrtime(true) / 1e9 + 5.0;
        while (!$condition()) {
            if (hrtime(true) / 1e9 >= $deadline) {
                self::fail('Isolated scheduler loop did not reach expected state: ' . $this->diagnostic($this->stderr));
            }
            usleep(10000);
        }
    }

    /** @param resource $stream */
    private function diagnostic(mixed $stream): string
    {
        return (string) file_get_contents(stream_get_meta_data($stream)['uri'], false, null, 0, 4096);
    }

    protected function tearDown(): void
    {
        $this->child = null; // Direct-child destructor also reaps failed fixture runs before schema cleanup.
        if ($this->barrierDirectory !== null && is_dir($this->barrierDirectory)) {
            foreach (['waiting', 'release'] as $marker) {
                $path = $this->barrierDirectory . '/' . $marker;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->barrierDirectory);
            $this->barrierDirectory = null;
        }
        if (isset($this->database)) {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            $this->database->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->database->close();
        }
        if (isset($this->redis)) {
            $this->redis->cleanup();
            $this->redis->close();
        }
        foreach ([$this->stdout ?? null, $this->stderr ?? null] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
