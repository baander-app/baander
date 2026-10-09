<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Application\Actor;
use App\Shared\Application\DTO\JobCancellation;
use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\QueuedJobWorkInterface;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorAdministration;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class JobMonitorAdministrationTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> statements run on the connection */
    private array $statements = [];

    private int $claimResult = 1;

    /** @var array<string, string> the Redis keys that were set */
    private array $redisKeys = [];

    private QueuedWorkStub $queuedWork;

    /** How often a whole job row was loaded. */
    private int $entityLoads = 0;

    /** @var list<string> DQL queries run */
    private array $dql = [];

    /** @var list<array<string, mixed>> rows the DQL queries answer */
    private array $rows = [];

    /** @return iterable<string, array{string|null}> */
    public static function queues(): iterable
    {
        yield 'recorded receiver' => ['async'];
        yield 'normal routing' => [null];
    }

    #[DataProvider('queues')]
    public function testRetryDispatchesTheMessageOnceUnderANewJobIdAndAuditsTheActor(?string $queue): void
    {
        $job = $this->failedJob($queue);
        $recorded = new InMemoryTransport();
        $default = new InMemoryTransport();
        $container = new Container();
        $container->set('async', $recorded);
        $container->set('default', $default);
        $bus = new MessageBus([new SendMessageMiddleware(new SendersLocator([
            ExtractAlbumCoverCommand::class => ['default'],
        ], $container))]);

        $newJobId = $this->administration($job, $bus)->retry($job->getJobId(), Actor::CLI);

        $sent = ($queue === null ? $default : $recorded)->getSent();
        self::assertCount(1, $sent);
        self::assertCount(0, ($queue === null ? $recorded : $default)->getSent());
        self::assertInstanceOf(ExtractAlbumCoverCommand::class, $sent[0]->getMessage());
        self::assertSame($newJobId, $sent[0]->last(JobIdStamp::class)?->jobId->toString());
        self::assertNotSame($job->getJobId(), $newJobId);
        self::assertStringContainsString('retried = false', $this->statements[0][0], 'The claim takes only an unretried job.');
        self::assertTrue($job->isRetried());
        $audit = json_decode((string) $job->getAuditLog(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('retry', $audit[0]['action']);
        self::assertSame($newJobId, $audit[0]['newJobId']);
        self::assertSame('cli', $audit[0]['userId']);
    }

    public function testADispatchFailureGivesTheRetryClaimBack(): void
    {
        $job = $this->failedJob(null);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Transport unavailable'));

        try {
            $this->administration($job, $bus, expectFlush: false)->retry($job->getJobId(), 'admin@baander.app');
            self::fail('Dispatch failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Transport unavailable', $exception->getMessage());
        }

        self::assertCount(2, $this->statements);
        self::assertStringContainsString('SET retried = false', $this->statements[1][0]);
        self::assertNull($job->getAuditLog());
    }

    public function testARetryThatLosesTheClaimIsAConflictAndDispatchesNothing(): void
    {
        $job = $this->failedJob(null);
        $this->claimResult = 0;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->expectException(ConflictException::class);

        $this->administration($job, $bus, expectFlush: false)->retry($job->getJobId(), Actor::CLI);
    }

    /** @return iterable<string, array{\Closure(JobMonitorEntity): void, string}> */
    public static function unretriableJobs(): iterable
    {
        yield 'running' => [static fn (JobMonitorEntity $job) => $job->setStatus(JobStatus::Running), 'Only failed jobs can be retried.'];
        yield 'already retried' => [static fn (JobMonitorEntity $job) => $job->markRetried(), 'This job has already been retried.'];
        yield 'no payload' => [static fn (JobMonitorEntity $job) => $job->setData(null), 'No message payload stored for this job.'];
        yield 'unreadable payload' => [static fn (JobMonitorEntity $job) => $job->setData('{"not":"a message"}'), 'The stored message payload cannot be read.'];
    }

    /** @param \Closure(JobMonitorEntity): void $change */
    #[DataProvider('unretriableJobs')]
    public function testAJobThatCannotBeRetriedIsAConflict(\Closure $change, string $message): void
    {
        $job = $this->failedJob(null);
        $change($job);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        try {
            $this->administration($job, $bus, expectFlush: false)->retry($job->getJobId(), Actor::CLI);
            self::fail('A conflict was expected.');
        } catch (ConflictException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->statements);
    }

    public function testAnUnknownJobIsNotFound(): void
    {
        $administration = $this->administration(null, $this->createStub(MessageBusInterface::class), expectFlush: false);

        foreach ([
            static fn () => $administration->job('missing'),
            static fn () => $administration->retry('missing', Actor::CLI),
            static fn () => $administration->cancel('missing'),
        ] as $call) {
            try {
                $call();
                self::fail('Not found was expected.');
            } catch (NotFoundException $exception) {
                self::assertSame('Job not found.', $exception->getMessage());
            }
        }
    }

    public function testCancellingSetsTheFlagThatCheckCancellationReads(): void
    {
        $job = new JobMonitorEntity('running-job');
        $job->markStarted();
        $administration = $this->administration($job, $this->createStub(MessageBusInterface::class), expectFlush: false);

        $administration->checkCancellation('running-job');
        self::assertSame(JobCancellation::Requested, $administration->cancel('running-job'));

        self::assertSame(['job_cancel:running-job' => '1'], $this->redisKeys);
        self::assertSame(0, $this->entityLoads, 'Cancelling reads only the status, not the stored message.');
        self::assertSame([['SELECT status FROM job_monitors WHERE job_id = :job_id', ['job_id' => 'running-job']]], $this->statements);
        $this->expectException(JobCancelledException::class);
        $administration->checkCancellation('running-job');
    }

    /** @return iterable<string, array{JobStatus, string}> */
    public static function completedStatuses(): iterable
    {
        yield 'finished' => [JobStatus::Finished, 'Finished jobs cannot be cancelled.'];
        yield 'failed' => [JobStatus::Failed, 'Failed jobs cannot be cancelled.'];
    }

    #[DataProvider('completedStatuses')]
    public function testCancellingACompletedJobIsAConflict(JobStatus $status, string $message): void
    {
        $job = new JobMonitorEntity('completed-job');
        $job->setStatus($status);
        // Another job's queued work is not this job's.
        $administration = $this->administration($job, $this->createStub(MessageBusInterface::class), expectFlush: false, queuedWork: ['other-job']);

        try {
            $administration->cancel('completed-job');
            self::fail('A conflict was expected.');
        } catch (ConflictException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->redisKeys);
        self::assertSame([], $this->queuedWork->cancelled);
        self::assertSame(0, $this->entityLoads);
        self::assertCount(1, $this->statements, 'The job stays as it is.');
    }

    public function testCancellingAFinishedJobStopsTheWorkItQueuedAndCancelsTheJob(): void
    {
        $job = new JobMonitorEntity('finished-job');
        $job->setStatus(JobStatus::Finished);
        $administration = $this->administration($job, $this->createStub(MessageBusInterface::class), expectFlush: false, queuedWork: ['finished-job']);

        self::assertSame(JobCancellation::QueuedWorkCancelled, $administration->cancel('finished-job'));

        self::assertSame(['finished-job'], $this->queuedWork->cancelled);
        self::assertSame([], $this->redisKeys, 'A finished job has no run left to read a flag.');
        [$sql, $params] = $this->statements[array_key_last($this->statements)];
        self::assertStringStartsWith('UPDATE job_monitors SET status = :status', $sql);
        self::assertStringContainsString('WHERE job_id = :job_id AND status = :finished', $sql);
        self::assertSame(JobStatus::Cancelled->value, $params['status']);
        self::assertSame('finished-job', $params['job_id']);
        self::assertSame(JobStatus::Finished->value, $params['finished']);
    }

    /** @return iterable<string, array{JobStatus, list<string>, bool}> */
    public static function cancellableJobs(): iterable
    {
        yield 'running' => [JobStatus::Running, [], true];
        yield 'finished with queued work' => [JobStatus::Finished, ['detail-job'], true];
        yield 'finished' => [JobStatus::Finished, ['other-job'], false];
        yield 'failed' => [JobStatus::Failed, ['detail-job'], false];
        yield 'cancelled' => [JobStatus::Cancelled, ['detail-job'], false];
    }

    /** @param list<string> $queuedWork */
    #[DataProvider('cancellableJobs')]
    public function testAJobsRecordSaysWhetherCancellingItCanStillChangeAnything(JobStatus $status, array $queuedWork, bool $cancellable): void
    {
        $job = new JobMonitorEntity('detail-job');
        $job->setStatus($status);

        $record = $this->administration($job, $this->createStub(MessageBusInterface::class), expectFlush: false, queuedWork: $queuedWork)
            ->job('detail-job');

        self::assertSame($cancellable, $record->cancellable);
    }

    public function testTheJobListLeavesOutTheStoredMessageAndTheError(): void
    {
        $this->rows = [$this->summaryRow('listed-job', JobStatus::Failed)];

        $page = $this->administration(null, $this->createStub(MessageBusInterface::class), expectFlush: false)
            ->jobs(new JobMonitorQuery(limit: 10));

        self::assertCount(1, $page->items);
        $job = $page->items[0];
        self::assertSame('listed-job', $job->jobId);
        self::assertSame('ExtractAlbumCoverCommand', $job->name);
        self::assertSame('async', $job->queue);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertSame(40, $job->progress);
        self::assertSame(2, $job->attempt);
        self::assertTrue($job->retried);
        self::assertEquals(new \DateTimeImmutable('2026-10-08 10:00:01.250000+00:00'), $job->startedAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-08 10:00:03.500000+00:00'), $job->finishedAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-08 10:00:00+00:00'), $job->createdAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-08 10:00:04+00:00'), $job->updatedAt);
        self::assertSame(\DomainException::class, $job->exceptionClass);
        self::assertTrue($job->dataTruncated);
        self::assertSame(2_250_000, $job->durationMicroseconds);
        self::assertNull($job->data);
        self::assertNull($job->exception);
        self::assertSame(0, $this->entityLoads);
        self::assertCount(1, $this->dql);
        self::assertDoesNotMatchRegularExpression('/SELECT j FROM|j\.data\b(?!Truncated)|j\.exception\b(?!Class)|j\.auditLog/', $this->dql[0]);
    }

    public function testTheRunningJobsLeaveOutTheStoredMessageAndTheError(): void
    {
        $this->rows = [$this->summaryRow('running-job', JobStatus::Running)];

        $overview = $this->administration(null, $this->createStub(MessageBusInterface::class), expectFlush: false)->overview();

        self::assertCount(1, $overview->running);
        self::assertSame('running-job', $overview->running[0]->jobId);
        self::assertSame(JobStatus::Running, $overview->running[0]->status);
        self::assertNull($overview->running[0]->data);
        self::assertNull($overview->running[0]->exception);
        self::assertSame(0, $this->entityLoads);
        $running = $this->dql[array_key_last($this->dql)];
        self::assertStringContainsString('WHERE j.status = :status ORDER BY j.startedAt ASC', $running);
        self::assertDoesNotMatchRegularExpression('/SELECT j FROM|j\.data\b(?!Truncated)|j\.exception\b(?!Class)|j\.auditLog/', $running);
    }

    public function testAnUnknownStatusFilterIsInvalidInput(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('status must be one of: queued, running, finished, failed, cancelled.');

        $this->administration(null, $this->createStub(MessageBusInterface::class), expectFlush: false)
            ->jobs(new JobMonitorQuery(status: 'stuck'));
    }

    public function testPruningLessThanADayIsInvalidInput(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Days must be at least 1.');

        $this->administration(null, $this->createStub(MessageBusInterface::class), expectFlush: false)->prune(0);
    }

    public function testAnInlineRunIsRecordedAsAJobThatFinishes(): void
    {
        $handled = [];
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExtractAlbumCoverCommand::class => [static function (ExtractAlbumCoverCommand $command) use (&$handled): string {
                $handled[] = $command;

                return 'cover extracted';
            }],
        ]))]);
        $message = new ExtractAlbumCoverCommand(Uuid::generate());

        $run = $this->administration(null, $bus, expectFlush: false)->runInline($message);

        self::assertSame([$message], $handled);
        self::assertSame('cover extracted', $run->result);
        [$startSql, $start] = $this->statements[0];
        self::assertStringContainsString('INSERT INTO job_monitors', $startSql);
        self::assertSame($run->jobId, $start['job_id']);
        self::assertSame('ExtractAlbumCoverCommand', $start['name']);
        self::assertNull($start['queue']);
        self::assertSame(JobStatus::Running->value, $start['status']);
        self::assertNotNull($start['data'], 'The stored message lets the monitor retry the run.');
        self::assertSame(JobStatus::Finished->value, $this->statements[1][1]['status']);
        self::assertSame($run->jobId, $this->statements[1][1]['job_id']);
    }

    public function testAFailedInlineRunIsRecordedWithTheHandlersOwnError(): void
    {
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExtractAlbumCoverCommand::class => [static function (): never {
                throw new \DomainException('The album has no cover.');
            }],
        ]))]);

        try {
            $this->administration(null, $bus, expectFlush: false)->runInline(new ExtractAlbumCoverCommand(Uuid::generate()));
            self::fail('The handler error must propagate.');
        } catch (\DomainException $exception) {
            self::assertSame('The album has no cover.', $exception->getMessage());
        }

        $failed = $this->statements[1][1];
        self::assertSame(JobStatus::Failed->value, $failed['status']);
        self::assertSame(\DomainException::class, $failed['exception_class']);
    }

    public function testAnInlineRunIsHandledInThisProcessEvenWhenTheMessageIsRoutedToATransport(): void
    {
        $transport = new InMemoryTransport();
        $container = new Container();
        $container->set('async', $transport);
        $handled = 0;
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExtractAlbumCoverCommand::class => ['async']], $container)),
            new HandleMessageMiddleware(new HandlersLocator([
                ExtractAlbumCoverCommand::class => [static function (ExtractAlbumCoverCommand $command, ...$rest) use (&$handled): void {
                    ++$handled;
                }],
            ])),
        ]);

        $this->administration(null, $bus, expectFlush: false)->runInline(new ExtractAlbumCoverCommand(Uuid::generate()));

        self::assertSame(1, $handled);
        self::assertSame([], $transport->getSent());
    }

    public function testAnInlineRunReachesAHandlerBoundToTheTransportTheMessageIsRoutedTo(): void
    {
        $handled = 0;
        $handler = static function (ExtractAlbumCoverCommand $command) use (&$handled): void {
            ++$handled;
        };
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExtractAlbumCoverCommand::class => [new HandlerDescriptor($handler, ['from_transport' => 'async'])],
        ]))]);

        $this->administration(null, $bus, expectFlush: false, routing: [ExtractAlbumCoverCommand::class => ['async']])
            ->runInline(new ExtractAlbumCoverCommand(Uuid::generate()));

        self::assertSame(1, $handled);
    }

    /** @return array<string, mixed> a row as the summary query hydrates it */
    private function summaryRow(string $jobId, JobStatus $status): array
    {
        return [
            'id' => Uuid::generate(),
            'jobId' => $jobId,
            'name' => 'ExtractAlbumCoverCommand',
            'queue' => 'async',
            'status' => $status,
            'progress' => 40,
            'attempt' => 2,
            'retried' => true,
            'startedAt' => new \DateTimeImmutable('2026-10-08 10:00:01.250000+00:00'),
            'finishedAt' => new \DateTimeImmutable('2026-10-08 10:00:03.500000+00:00'),
            'createdAt' => new \DateTimeImmutable('2026-10-08 10:00:00+00:00'),
            'updatedAt' => new \DateTimeImmutable('2026-10-08 10:00:04+00:00'),
            'exceptionClass' => \DomainException::class,
            'dataTruncated' => true,
            'durationMicroseconds' => 2_250_000,
        ];
    }

    private function failedJob(?string $queue): JobMonitorEntity
    {
        $job = new JobMonitorEntity('original-job', queue: $queue);
        $job->markFailed();
        $job->setData((new JobMessageSerializer(MessageCodecFactory::create()))->serialize(
            new Envelope(new ExtractAlbumCoverCommand(Uuid::generate()), [new ReceivedStamp('async')]),
        ));

        return $job;
    }

    /**
     * @param array<class-string, list<string>> $routing message class => transport names
     * @param list<string> $queuedWork the jobs with queued work waiting
     */
    private function administration(?JobMonitorEntity $job, MessageBusInterface $bus, bool $expectFlush = true, array $routing = [], array $queuedWork = []): JobMonitorAdministration
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function () use ($job): ?JobMonitorEntity {
            ++$this->entityLoads;

            return $job;
        });
        $repository->method('createQueryBuilder')->willReturnCallback(
            static fn (string $alias): QueryBuilder => (new QueryBuilder($entityManager))->select($alias)->from(JobMonitorEntity::class, $alias),
        );
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->statements[] = [$sql, $params];

            return str_contains($sql, 'retried = true') ? $this->claimResult : 1;
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params = []) use ($job): int|string|false {
            $this->statements[] = [$sql, $params];

            if (str_starts_with($sql, 'SELECT status')) {
                return $job?->getStatus()->value ?? false;
            }

            return 1;
        });
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->dql[] = $dql;
            $query = $this->createStub(Query::class);
            $query->method('setParameters')->willReturnSelf();
            $query->method('setFirstResult')->willReturnSelf();
            $query->method('setMaxResults')->willReturnSelf();
            // The status counts answer nothing; the job rows answer the summary queries.
            $query->method('getResult')->willReturn(str_contains($dql, 'COUNT(') ? [] : $this->rows);

            return $query;
        });
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects($expectFlush ? $this->once() : $this->never())->method('flush');
        $encoder = new JsonEncoder();

        return new JobMonitorAdministration(
            new JobMonitorService($entityManager, new CursorPaginator(), $encoder),
            new CursorCodec($encoder),
            $bus,
            new JobMessageSerializer(MessageCodecFactory::create()),
            $this->redis(),
            new NullLogger(),
            new SendersLocator($routing, $this->transports($routing)),
            [$this->queuedWork = new QueuedWorkStub($queuedWork)],
        );
    }

    /** @param array<class-string, list<string>> $routing */
    private function transports(array $routing): Container
    {
        $container = new Container();
        foreach (array_merge([], ...array_values($routing)) as $name) {
            $container->set($name, new InMemoryTransport());
        }

        return $container;
    }

    private function redis(): RedisClientFactory
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('setex')->willReturnCallback(function (string $key, int $ttl, string $value): bool {
            self::assertSame(3600, $ttl);
            $this->redisKeys[$key] = $value;

            return true;
        });
        $redis->method('exists')->willReturnCallback(fn (string $key): int => isset($this->redisKeys[$key]) ? 1 : 0);
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));

        return $factory;
    }
}

/** Queued work of the given jobs; records the jobs whose work was cancelled. */
final class QueuedWorkStub implements QueuedJobWorkInterface
{
    /** @var list<string> */
    public array $cancelled = [];

    /** @param list<string> $jobIds the jobs with queued work waiting */
    public function __construct(private readonly array $jobIds)
    {
    }

    public function hasQueuedWork(string $jobId): bool
    {
        return in_array($jobId, $this->jobIds, true);
    }

    public function cancelQueuedWork(string $jobId): bool
    {
        if (!$this->hasQueuedWork($jobId)) {
            return false;
        }
        $this->cancelled[] = $jobId;

        return true;
    }
}
