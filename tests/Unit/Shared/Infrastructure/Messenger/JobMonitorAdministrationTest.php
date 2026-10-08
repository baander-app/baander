<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Application\Actor;
use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\JobCancelledException;
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
        $administration->cancel('running-job');

        self::assertSame(['job_cancel:running-job' => '1'], $this->redisKeys);
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

        try {
            $this->administration($job, $this->createStub(MessageBusInterface::class), expectFlush: false)->cancel('completed-job');
            self::fail('A conflict was expected.');
        } catch (ConflictException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->redisKeys);
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

    private function failedJob(?string $queue): JobMonitorEntity
    {
        $job = new JobMonitorEntity('original-job', queue: $queue);
        $job->markFailed();
        $job->setData((new JobMessageSerializer(MessageCodecFactory::create()))->serialize(
            new Envelope(new ExtractAlbumCoverCommand(Uuid::generate()), [new ReceivedStamp('async')]),
        ));

        return $job;
    }

    /** @param array<class-string, list<string>> $routing message class => transport names */
    private function administration(?JobMonitorEntity $job, MessageBusInterface $bus, bool $expectFlush = true, array $routing = []): JobMonitorAdministration
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($job);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->statements[] = [$sql, $params];

            return str_contains($sql, 'retried = true') ? $this->claimResult : 1;
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->statements[] = [$sql, $params];

            return 1;
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
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
