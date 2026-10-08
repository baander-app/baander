<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\SwooleTaskJobMonitorDecorator;
use App\Shared\Infrastructure\Messenger\WorkerJobMonitorSubscriber;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Messaging\CancellationProbe;
use App\Tests\Fixtures\Messaging\CancellationProbeHandler;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Tests\Functional\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger\SwooleServerTaskTransportHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * `app:monitor:job:cancel` on a running job stops it at its next checkpoint, and the job's
 * row ends `cancelled`, on each path that runs jobs: an inline run from the console, a
 * Messenger worker and a Swoole task worker. A cancelled job is not retried and does not
 * reach the failure transport.
 */
final class JobCancellationTest extends TestCase
{
    /** @var list<string> jobs whose cancel flag this test may have set */
    private array $jobIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        CancellationProbeHandler::reset();
        // Cancel the probe's job from the console after its first item.
        CancellationProbeHandler::$afterItem = function (CancellationProbe $probe, int $item): void {
            if ($item !== 1) {
                return;
            }
            $jobId = $this->runningJobId();
            $this->jobIds[] = $jobId;
            $cancel = new CommandTester((new Application($this->client->getKernel()))->find('app:monitor:job:cancel'));
            self::assertSame(Command::SUCCESS, $cancel->execute(['jobId' => $jobId]), $cancel->getDisplay());
        };
    }

    protected function tearDown(): void
    {
        CancellationProbeHandler::reset();
        if ($this->jobIds !== []) {
            static::getContainer()->get(RedisClientFactory::class)->borrow(
                fn (\Redis $redis): mixed => $redis->del(...array_map(static fn (string $jobId): string => 'job_cancel:' . $jobId, $this->jobIds)),
            );
        }

        parent::tearDown();
    }

    public function testAnInlineRunStopsAtItsNextCheckpointAndEndsCancelled(): void
    {
        try {
            static::getContainer()->get(JobMonitorAdministrationInterface::class)->runInline(new CancellationProbe('inline', 3));
            self::fail('A cancelled inline run must report the cancellation.');
        } catch (JobCancelledException $exception) {
            self::assertStringContainsString($this->jobIds[0], $exception->getMessage());
        }

        self::assertSame(['inline:1'], CancellationProbeHandler::$handled);
        $this->assertCancelled($this->jobIds[0], null);
    }

    public function testAWorkerJobStopsAndIsAcknowledgedWithoutARetryOrTheFailureTransport(): void
    {
        $async = new InMemoryTransport();
        $failed = new InMemoryTransport();
        $async->send(new Envelope(new CancellationProbe('worker', 3), [new JobIdStamp(new PublicId())]));
        $subscriber = static::getContainer()->get(WorkerJobMonitorSubscriber::class);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, $subscriber->onMessageReceived(...));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $subscriber->onMessageHandled(...));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $subscriber->onMessageFailed(...));
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $async]),
            new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(3, 0, jitter: 0)]),
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $failed]),
        ));
        $worker = new Worker(['async' => $async], static::getContainer()->get(MessageBusInterface::class), $dispatcher);
        $stop = static function () use ($worker): void {
            $worker->stop();
        };
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $stop, -1000);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $stop, -1000);

        $worker->run(['sleep' => 0]);

        self::assertSame(['worker:1'], CancellationProbeHandler::$handled);
        self::assertCount(1, $async->getAcknowledged());
        self::assertSame([], $async->getRejected());
        self::assertCount(1, $async->getSent(), 'The cancelled job was not sent again for a retry.');
        self::assertSame([], $failed->getSent(), 'The cancelled job did not reach the failure transport.');
        $this->assertCancelled($this->jobIds[0], 'async');
    }

    public function testASwooleTaskStopsAndEndsCancelled(): void
    {
        $container = static::getContainer();
        $serializer = new JsonTransportSerializer(MessageCodecFactory::create());
        $logs = new TestHandler();
        $decorator = new SwooleTaskJobMonitorDecorator(
            new SwooleServerTaskTransportHandler($container->get(MessageBusInterface::class), $serializer),
            $container->get(JobMonitorService::class),
            $container->get(JobMessageSerializer::class),
            new Logger('test', [$logs]),
            $serializer,
        );
        $task = new \Swoole\Server\Task();
        $task->data = new Envelope(new CancellationProbe('task', 3), [new JobIdStamp(new PublicId()), new ReceivedStamp('swoole_task')]);

        $decorator->handle(new \Swoole\Server('127.0.0.1', 0), $task);

        self::assertSame(['task:1'], CancellationProbeHandler::$handled);
        $this->assertCancelled($this->jobIds[0], 'swoole_task');
        self::assertFalse($logs->hasInfoThatContains('Job completed'), 'A cancelled task is not logged as completed.');
        self::assertTrue($logs->hasInfoThatContains('Job ended without finishing its attempt'));
    }

    private function runningJobId(): string
    {
        $jobIds = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT job_id FROM job_monitors WHERE name = 'CancellationProbe' AND status = 'running'",
        );
        self::assertCount(1, $jobIds);

        return (string) $jobIds[0];
    }

    private function assertCancelled(string $jobId, ?string $queue): void
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT status, queue, attempt, exception_class, finished_at IS NOT NULL AS finished FROM job_monitors WHERE job_id = ?',
            [$jobId],
        );
        self::assertSame(
            ['status' => 'cancelled', 'queue' => $queue, 'attempt' => 1, 'exception_class' => null, 'finished' => true],
            $row,
        );
    }
}
