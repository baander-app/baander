<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Scheduler\Domain\Exception\ScheduledJobConflict;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Model\ScheduledJobState;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Infrastructure\Doctrine\Repository\ScheduledJobRepository;
use App\Shared\Domain\Model\Uuid;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\MessageBusInterface;

/** Independent configured repositories and committed observer; no synthetic revision checks or timing races. */
#[SkipDatabaseRollback]
final class ScheduledJobConcurrencyTest extends TestCase
{
    private Kernel $kernel;
    private EntityManagerInterface $manager;
    private EntityManager $otherManager;
    private Connection $otherConnection;
    private Connection $observer;
    private ScheduledJobRepositoryInterface $jobs;
    private ScheduledJobRepository $otherJobs;
    /** @var list<Uuid> */
    private array $ownedIds = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same migrated disposable PostgreSQL database.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $parameters['serverVersion'] = '18';
        $this->observer = DriverManager::getConnection($parameters);
        $this->otherConnection = DriverManager::getConnection($parameters);
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel());
        self::assertSame($this->observer->fetchOne('SELECT current_database()'), $manager->getConnection()->fetchOne('SELECT current_database()'));
        $this->jobs = $container->get(ScheduledJobRepositoryInterface::class);
        $this->otherManager = new EntityManager($this->otherConnection, $manager->getConfiguration(), $manager->getEventManager());
        $this->otherJobs = new ScheduledJobRepository($this->otherManager);
    }

    public function testStaleExecutionResultCannotOverwriteAnotherWritersEditedAndPausedConfiguration(): void
    {
        $stale = $this->createJob();
        $this->manager->clear();
        $other = $this->otherJob($stale->getId());
        $other->update('Edited by other writer', '*/5 * * * *', JobType::Console, 'app:other-writer', 'Fresh description', ['address' => 'new@baander.app', 'limit' => 2]);
        $other->pause();
        $this->otherJobs->save($other);
        $committed = $this->row($stale->getId());
        $stale->markSuccess('Stale execution returned');
        $this->assertConflict(fn () => $this->jobs->save($stale));
        self::assertSame($committed, $this->row($stale->getId()));
        self::assertSame('paused', $committed['status']);
        self::assertSame('app:other-writer', $committed['command']);
        self::assertSame(0, (int) $committed['run_count']);
    }

    public function testDeletedLoadedJobCannotBeRecreatedByAnExecutionSave(): void
    {
        $stale = $this->createJob();
        $this->manager->clear();
        $this->otherJobs->delete($this->otherJob($stale->getId()));
        $stale->markSuccess('Completed after deletion');
        $this->assertConflict(fn () => $this->jobs->save($stale));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM scheduled_jobs WHERE id = :id', ['id' => $stale->getId()->toString()]));
        self::assertNull($this->jobs->findByUuid($stale->getId()));
    }

    public function testActualHandlerResultCannotOverwritePauseAndEditDuringConsoleExecution(): void
    {
        $job = $this->createJob();
        $snapshot = new SchedulerOccurrence(Uuid::generate(), $job->getId(), new \DateTimeImmutable('2026-10-02T10:00:00Z'), $job->getJobType(), $job->getCommand(), $job->getParameters());
        $registeredCommand = new class extends Command implements SchedulableConsoleCommandInterface {
            public function __construct() { parent::__construct('app:original'); }
            /** @return array<string, array<string, mixed>> */
            public static function schedulerParameters(): array { return []; }
        };
        $committed = null;
        $console = $this->createMock(ScheduledConsoleExecutorInterface::class);
        $console->expects(self::once())->method('execute')->willReturnCallback(
            /** @param array<array-key, mixed> $parameters */
            function (string $command, array $parameters) use ($job, &$committed): string {
            self::assertSame('app:original', $command);
            self::assertSame($job->getParameters(), $parameters);
            $other = $this->otherJob($job->getId());
            self::assertNotNull($other->getLastRunAt(), 'The handler running marker was committed before invoking the console effect.');
            $other->update('Changed during execution', '*/5 * * * *', JobType::Console, 'app:replacement', null, ['address' => 'edited@baander.app']);
            $other->pause();
            $this->otherJobs->save($other);
            $committed = $this->row($job->getId());
            return 'Console returned after another writer paused';
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $jobPort = $this->kernel->getContainer()->get('test.service_container')->get(ScheduledJobPortInterface::class);
        $handler = new ExecuteScheduledJobHandler($jobPort, new SchedulerRegistry([], [$registeredCommand]), $bus, new NullLogger(), $console);
        $this->assertConflict(fn () => $handler->executeOccurrence($snapshot));
        self::assertIsArray($committed);
        self::assertSame($committed, $this->row($job->getId()));
        self::assertSame('paused', $committed['status']);
        self::assertSame('app:replacement', $committed['command']);
        self::assertSame(0, (int) $committed['run_count']);
        self::assertNull($committed['last_result']);
    }

    public function testNoOpSaveStillRotatesRevisionAndRejectsAnotherNoOpStaleSnapshot(): void
    {
        $stale = $this->createJob();
        $before = $stale->getState()->revision;
        self::assertInstanceOf(Uuid::class, $before);
        $other = $this->otherJob($stale->getId());
        $this->otherJobs->save($other);
        self::assertInstanceOf(Uuid::class, $other->getState()->revision);
        self::assertFalse($before->equals($other->getState()->revision));
        $this->manager->clear();
        $committed = $this->row($stale->getId());
        $this->assertConflict(fn () => $this->jobs->save($stale));
        self::assertSame($committed, $this->row($stale->getId()));
    }

    public function testStaleDeleteCannotRemoveAnotherWritersNewerConfiguration(): void
    {
        $stale = $this->createJob();
        $this->manager->clear();
        $other = $this->otherJob($stale->getId());
        $other->disable();
        $this->otherJobs->save($other);
        $committed = $this->row($stale->getId());
        $this->assertConflict(fn () => $this->jobs->delete($stale));
        self::assertSame($committed, $this->row($stale->getId()));
        self::assertSame('disabled', $committed['status']);
    }

    public function testDeleteAndRecreateSameIdCannotRevalidateOldSnapshot(): void
    {
        $stale = $this->createJob();
        $oldRevision = $stale->getState()->revision;
        self::assertInstanceOf(Uuid::class, $oldRevision);
        $this->manager->clear();
        $this->otherJobs->delete($this->otherJob($stale->getId()));
        $now = new \DateTimeImmutable();
        $replacement = ScheduledJob::reconstitute(new ScheduledJobState(
            id: $stale->getId(), name: 'Explicit replacement', expression: '* * * * *', jobType: JobType::Console,
            command: 'app:replacement', status: ScheduleStatus::Active, description: null,
            parameters: ['address' => 'replacement@baander.app'], createdAt: $now, updatedAt: $now,
        ));
        $this->otherJobs->save($replacement);
        self::assertInstanceOf(Uuid::class, $replacement->getState()->revision);
        self::assertFalse($oldRevision->equals($replacement->getState()->revision));
        $committed = $this->row($stale->getId());
        $stale->markSuccess('Old incarnation');
        $this->assertConflict(fn () => $this->jobs->save($stale));
        $this->assertConflict(fn () => $this->jobs->delete($stale));
        self::assertSame($committed, $this->row($stale->getId()));
        self::assertSame('app:replacement', $committed['command']);
    }

    public function testOuterRollbackMakesAdvancedSnapshotUnusableAndReloadCanSave(): void
    {
        $job = $this->createJob();
        $committed = $this->row($job->getId());
        $before = $job->getState()->revision;
        self::assertInstanceOf(Uuid::class, $before);
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        try {
            $job->pause();
            $this->jobs->save($job);
            self::assertSame(1, $connection->getTransactionNestingLevel(), 'Repository save must not commit its caller transaction.');
            self::assertInstanceOf(Uuid::class, $job->getState()->revision);
            self::assertFalse($before->equals($job->getState()->revision));
            self::assertSame($committed, $this->row($job->getId()), 'Independent observer cannot see provisional state.');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        $this->manager->clear();
        $this->assertConflict(fn () => $this->jobs->save($job));
        self::assertSame($committed, $this->row($job->getId()));
        $fresh = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $fresh);
        self::assertSame(ScheduleStatus::Active, $fresh->getStatus());
        self::assertInstanceOf(Uuid::class, $fresh->getState()->revision);
        self::assertTrue($before->equals($fresh->getState()->revision));
        $fresh->markSuccess('Fresh after rollback');
        $this->jobs->save($fresh);
        self::assertSame('Fresh after rollback', $this->row($job->getId())['last_result']);
    }

    private function createJob(): ScheduledJob
    {
        $job = ScheduledJob::create('Concurrency ' . bin2hex(random_bytes(6)), '* * * * *', JobType::Console, 'app:original', parameters: ['address' => 'scheduler@baander.app']);
        $this->ownedIds[] = $job->getId();
        $this->jobs->save($job);
        self::assertInstanceOf(Uuid::class, $job->getState()->revision);
        $this->manager->clear();
        $loaded = $this->jobs->findByUuid($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $loaded);
        return $loaded;
    }

    private function otherJob(Uuid $id): ScheduledJob
    {
        $this->otherManager->clear();
        $job = $this->otherJobs->findByUuid($id);
        self::assertInstanceOf(ScheduledJob::class, $job);
        return $job;
    }

    /** @return array<string, mixed> */
    private function row(Uuid $id): array
    {
        $row = $this->observer->fetchAssociative('SELECT *, parameters::text AS parameter_json FROM scheduled_jobs WHERE id = :id', ['id' => $id->toString()]);
        self::assertIsArray($row);
        return $row;
    }

    private function assertConflict(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected a stale snapshot conflict.');
        } catch (ScheduledJobConflict $error) {
            self::assertSame('Scheduled job snapshot conflicts with current persistence state.', $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ([$this->manager ?? null, $this->otherManager ?? null] as $manager) {
                if ($manager !== null) {
                    $connection = $manager->getConnection();
                    while ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    $manager->clear();
                }
            }
            if (isset($this->observer)) {
                foreach ($this->ownedIds as $id) {
                    $this->observer->executeStatement('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => $id->toString()]);
                }
            }
        } finally {
            if (isset($this->otherManager)) {
                $this->otherManager->close();
            }
            if (isset($this->kernel)) {
                $this->kernel->shutdown();
            }
            foreach ([$this->otherConnection ?? null, $this->observer ?? null] as $connection) {
                $connection?->close();
            }
        }
    }
}
