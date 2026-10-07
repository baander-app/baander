<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JobAttemptStamp;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Messenger\WorkerJobMonitorSubscriber;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WorkerJobMonitorSubscriberTest extends TestCase
{
    /** @var array<string, mixed>|null parameters of the attempt start */
    private ?array $started = null;

    /** @var list<array<string, mixed>> parameters of attempt completions */
    private array $completed = [];

    private WorkerJobMonitorSubscriber $subscriber;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params): int {
            self::assertStringContainsString('ON CONFLICT (job_id) DO UPDATE', $sql);
            $this->started = $params;

            return 3;
        });
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            self::assertStringContainsString('WHERE job_id = :job_id AND attempt = :attempt', $sql);
            $this->completed[] = $params;

            return 1;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $this->subscriber = new WorkerJobMonitorSubscriber(
            new JobMonitorService($em, new CursorPaginator(), new JsonEncoder()),
            new JobMessageSerializer(MessageCodecFactory::create()),
        );
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function workerExecutions(): iterable
    {
        yield 'new ID handled' => [false, false];
        yield 'existing ID handled' => [true, false];
        yield 'new ID failed' => [false, true];
        yield 'existing ID failed' => [true, true];
    }

    #[DataProvider('workerExecutions')]
    public function testRealWorkerRetainsReceiverQueueJobIdAndPayload(bool $existingId, bool $fails): void
    {
        $jobId = new PublicId();
        $albumId = Uuid::generate();
        $command = new ExtractAlbumCoverCommand($albumId);
        $envelope = new Envelope($command, [new TransportNamesStamp(['original-requested-transport'])]);

        if ($existingId) {
            $envelope = $envelope->with(new JobIdStamp($jobId));
        }

        $transport = new InMemoryTransport();
        $transport->send($envelope);

        $seen = null;
        $capture = new class(function (Envelope $envelope) use (&$seen): void {
            $seen = $envelope;
        }) implements MiddlewareInterface {
            public function __construct(private readonly \Closure $capture)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->capture)($envelope);

                return $stack->next()->handle($envelope, $stack);
            }
        };

        $bus = new MessageBus([$capture, new HandleMessageMiddleware(new HandlersLocator([
            ExtractAlbumCoverCommand::class => [static function (ExtractAlbumCoverCommand $message) use ($fails, $albumId): void {
                self::assertTrue($albumId->equals($message->getAlbumId()));

                if ($fails) {
                    throw new \RuntimeException('Handler failed.');
                }
            }],
        ]))]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, $this->subscriber->onMessageReceived(...));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $this->subscriber->onMessageHandled(...));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $this->subscriber->onMessageFailed(...));
        $worker = new Worker(['actual-async-receiver' => $transport], $bus, $dispatcher);
        $stop = static function () use ($worker): void {
            $worker->stop();
        };
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $stop, -10);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $stop, -10);
        $worker->run(['sleep' => 0]);

        self::assertNotNull($this->started);
        self::assertSame('actual-async-receiver', $this->started['queue']);
        self::assertSame('ExtractAlbumCoverCommand', $this->started['name']);
        self::assertSame((MessageCodecFactory::create())->encode($command), $this->started['data']);
        self::assertFalse($this->started['data_truncated']);
        self::assertInstanceOf(Envelope::class, $seen);
        $stamp = $seen->last(JobIdStamp::class);
        self::assertNotNull($stamp);
        self::assertSame($this->started['job_id'], $stamp->jobId->toString());
        self::assertSame(3, $seen->last(JobAttemptStamp::class)?->attempt);

        if ($existingId) {
            self::assertSame($jobId, $stamp->jobId);
        }

        self::assertSame('actual-async-receiver', $seen->last(ReceivedStamp::class)?->getTransportName());
        self::assertCount(1, $this->completed);
        self::assertSame(($fails ? JobStatus::Failed : JobStatus::Finished)->value, $this->completed[0]['status']);
        self::assertSame($stamp->jobId->toString(), $this->completed[0]['job_id']);
        self::assertSame(3, $this->completed[0]['attempt']);
    }

    public function testUnsupportedPayloadIsMarkedTruncatedWithoutLosingQueueOrJobId(): void
    {
        $jobId = new PublicId();
        $event = new WorkerMessageReceivedEvent(new Envelope(new \stdClass(), [new JobIdStamp($jobId)]), 'receiver');
        $this->subscriber->onMessageReceived($event);

        self::assertNotNull($this->started);
        self::assertSame('receiver', $this->started['queue']);
        self::assertSame($jobId->toString(), $this->started['job_id']);
        self::assertNull($this->started['data']);
        self::assertTrue($this->started['data_truncated']);
    }

    /** @return iterable<string, array{Envelope}> */
    public static function deliveriesWithoutAStartedAttempt(): iterable
    {
        yield 'no job ID' => [new Envelope(new \stdClass(), [new JobAttemptStamp(1)])];
        yield 'no attempt' => [new Envelope(new \stdClass(), [new JobIdStamp(new PublicId())])];
    }

    #[DataProvider('deliveriesWithoutAStartedAttempt')]
    public function testTerminalEventsWithoutAStartedAttemptDoNotUpdateAnyJob(Envelope $envelope): void
    {
        $this->subscriber->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'receiver'));
        $this->subscriber->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'receiver', new \RuntimeException()));

        self::assertSame([], $this->completed);
    }
}
