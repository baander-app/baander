<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Infrastructure\Swoole\Drain\DrainBudget;
use App\Shared\Infrastructure\Swoole\Drain\DrainStateTable;
use App\Shared\Infrastructure\Swoole\Drain\WorkerDrain;
use App\Shared\Infrastructure\Swoole\Drain\WorkerDrainStartedEvent;
use App\Shared\Infrastructure\Swoole\Drain\WorkerStreamAborterInterface;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Timer;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Drain budget 10 s: window 3 s. Worker 0 of two event workers. */
final class WorkerDrainTest extends TestCase
{
    private const int WINDOW_NS = 3_000_000_000;

    private DrainStateTable $state;
    private WebSocketConnectionRegistry $registry;
    private Server&Stub $server;
    private EventDispatcher $dispatcher;
    /** @var list<WorkerDrainStartedEvent> */
    private array $drainEvents = [];
    /** @var list<int> concurrency answers, the last one repeated */
    private array $concurrency = [0];
    /** @var array<int, list<bool>> per fd, whether the connection still exists, the last one repeated */
    private array $exists = [];
    private int $nowNs = 1_000_000_000;

    protected function setUp(): void
    {
        $configuration = $this->createStub(HttpServerConfiguration::class);
        $configuration->method('getWorkerCount')->willReturn(2);
        $this->state = new DrainStateTable($configuration);
        $this->state->boot();
        $this->registry = WebSocketConnectionRegistry::create(maxConnections: 16, maxRoomMembers: 16);
        $this->server = $this->serverDouble($this->createStub(Server::class));
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(WorkerDrainStartedEvent::class, function (WorkerDrainStartedEvent $event): void {
            $this->drainEvents[] = $event;
        });
    }

    protected function tearDown(): void
    {
        Timer::clearAll();
    }

    public function testWithoutTheDrainFlagATickDoesNothing(): void
    {
        $this->registry->addConnection(10, 'user-1', 0, 'token-10');
        $this->server = $server = $this->serverDouble($this->createMock(Server::class));
        $server->expects(self::never())->method('stats');
        $server->expects(self::never())->method('push');
        $drain = $this->drain();

        $drain->poll($this->server);
        $drain->poll($this->server);

        self::assertSame([], $this->drainEvents);
        self::assertFalse($this->state->allWorkersDrained(1));
    }

    public function testEachStepRunsOnceAndTheWorkerReportsOnceOverThreeTicks(): void
    {
        $this->registry->addConnection(10, 'user-1', 0, 'token-10');
        $this->registry->addConnection(20, 'user-2', 1, 'token-20');
        $this->server = $server = $this->serverDouble($this->createMock(Server::class));
        $server->method('isEstablished')->willReturn(true);
        $server->expects(self::once())->method('push')
            ->with(10, self::callback(static fn (mixed $frame): bool => $frame instanceof CloseFrame
                && $frame->code === 1012 && $frame->reason === 'service restart'))
            ->willReturn(true);
        $this->state->begin($this->nowNs);
        $drain = $this->drain();

        $drain->poll($this->server);
        $drain->poll($this->server);
        $drain->poll($this->server);

        self::assertCount(1, $this->drainEvents);
        self::assertSame(0, $this->drainEvents[0]->getWorkerId());
        self::assertSame($this->server, $this->drainEvents[0]->getServer());
        self::assertTrue($this->state->allWorkersDrained(1));
        self::assertFalse($this->state->allWorkersDrained(2), 'Worker 1 reports for itself.');
    }

    public function testTheWorkerReportsOnlyOnceItsRequestsHaveFinished(): void
    {
        $this->concurrency = [2, 1, 0];
        $this->state->begin($this->nowNs);
        $drain = $this->drain();

        $drain->poll($this->server);
        $drain->poll($this->server);
        self::assertFalse($this->state->allWorkersDrained(1));

        $drain->poll($this->server);
        self::assertTrue($this->state->allWorkersDrained(1));
    }

    public function testARequestOnAKeptAliveConnectionWithdrawsTheReportUntilTheWorkerIsIdleAgain(): void
    {
        $this->concurrency = [0, 1, 1, 0];
        $this->state->begin($this->nowNs);
        $this->state->markWorkerDrained(1);
        $drain = $this->drain();

        $drain->poll($this->server);
        self::assertTrue($this->state->allWorkersDrained(2));

        // A request reached the reported worker: the master must not close the window under it.
        $drain->poll($this->server);
        self::assertFalse($this->state->allWorkersDrained(2));
        $drain->poll($this->server);
        self::assertFalse($this->state->allWorkersDrained(2));

        $drain->poll($this->server);
        self::assertTrue($this->state->allWorkersDrained(2));
        self::assertCount(1, $this->drainEvents, 'The drain steps run once.');
    }

    public function testAClosedWebSocketHoldsTheReportUntilSwooleHasClosedItsConnection(): void
    {
        $this->registry->addConnection(10, 'user-1', 0, 'token-10');
        $this->server->method('isEstablished')->willReturn(true);
        $this->server->method('push')->willReturn(true);
        // The client answers the close frame on the next tick.
        $this->exists[10] = [true, false];
        $this->state->begin($this->nowNs);
        $drain = $this->drain();

        $drain->poll($this->server);
        self::assertFalse($this->state->allWorkersDrained(1));

        $drain->poll($this->server);
        self::assertTrue($this->state->allWorkersDrained(1));
    }

    public function testStreamsStillOpenAreCutHalfASecondBeforeTheWindowEndsOnEachTickUntilTheWorkerIsIdle(): void
    {
        // A stream never ends on its own here; the aborter is the only way out.
        $this->concurrency = [1];
        $this->state->begin($this->nowNs);
        $streams = new class implements WorkerStreamAborterInterface {
            public int $aborts = 0;

            public function abortAll(): void
            {
                ++$this->aborts;
            }
        };
        $drain = $this->drain(streams: $streams);
        $cutAtNs = self::WINDOW_NS - WorkerDrain::STREAM_CUT_LEAD_MILLISECONDS * 1_000_000;
        self::assertSame(500, WorkerDrain::STREAM_CUT_LEAD_MILLISECONDS);

        $this->nowNs += $cutAtNs - 1;
        $drain->poll($this->server);
        self::assertSame(0, $streams->aborts);

        // A cut stream's coroutine may be busy in work that cannot be cancelled, so each tick
        // cuts again until the worker is idle.
        $this->nowNs += 1;
        $drain->poll($this->server);
        $drain->poll($this->server);
        self::assertSame(2, $streams->aborts);
        self::assertFalse($this->state->allWorkersDrained(1), 'A worker reports only once its streams have ended.');

        $this->concurrency = [0];
        $drain->poll($this->server);
        $drain->poll($this->server);
        self::assertSame(2, $streams->aborts);
        self::assertTrue($this->state->allWorkersDrained(1));
    }

    public function testWithoutAStreamAborterAStreamingWorkerNeverReports(): void
    {
        $this->concurrency = [1];
        $this->state->begin($this->nowNs);
        $drain = $this->drain();

        $this->nowNs += 2 * self::WINDOW_NS;
        $drain->poll($this->server);
        $drain->poll($this->server);

        self::assertFalse($this->state->allWorkersDrained(1));
    }

    public function testAWorkerWhosePusherHasNoServerYetSkipsTheWebSockets(): void
    {
        $this->registry->addConnection(10, 'user-1', 0, 'token-10');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $this->state->begin($this->nowNs);
        $drain = new WorkerDrain(
            $this->state,
            new DrainBudget(10),
            $this->dispatcher,
            $logger,
            new WebSocketPusher($this->registry, new JsonEncoder()),
            monotonicNs: fn (): int => $this->nowNs,
        );

        $drain->poll($this->server);

        self::assertCount(1, $this->drainEvents);
        self::assertTrue($this->state->allWorkersDrained(1));
    }

    public function testAFailingDrainListenerIsLoggedAndTheWorkerStillReports(): void
    {
        $this->dispatcher->addListener(WorkerDrainStartedEvent::class, static function (): never {
            throw new \RuntimeException('The state directory is read-only.');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('Web server worker 0 could not run its drain listeners during the drain.', self::callback(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException,
            ));
        $this->state->begin($this->nowNs);

        $this->drain(logger: $logger)->poll($this->server);

        self::assertTrue($this->state->allWorkersDrained(1));
    }

    /**
     * A constructed server keeps the timers' event loop from running when the test process ends.
     * It also leaves Swoole state behind that stops a later blocking socket read in the same
     * process from timing out (SocketServerControlClientTest hung), so the test runs alone.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlyEventWorkersStartTheTickAndStopClearsIt(): void
    {
        $taskWorker = new Server('127.0.0.1', 0);
        $taskWorker->taskworker = true;
        $eventWorker = new Server('127.0.0.1', 0);
        $drain = $this->drain();

        $drain->onWorkerStarted(new WorkerStartedEvent($taskWorker, 2));
        self::assertSame([], iterator_to_array(Timer::list()));

        $drain->onWorkerStarted(new WorkerStartedEvent($eventWorker, 0));
        $drain->onWorkerStarted(new WorkerStartedEvent($eventWorker, 0));
        self::assertCount(1, iterator_to_array(Timer::list()));

        $drain->stop();
        $drain->stop();
        self::assertSame([], iterator_to_array(Timer::list()));
    }

    /**
     * A constructed server keeps the timers' event loop from running when the test process ends.
     * It also leaves Swoole state behind that stops a later blocking socket read in the same
     * process from timing out (SocketServerControlClientTest hung), so the test runs alone.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTickKeepsRunningOnceTheWorkerHasReported(): void
    {
        $this->state->begin($this->nowNs);
        $drain = $this->drain();
        $drain->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 0));
        self::assertCount(1, iterator_to_array(Timer::list()));

        $drain->poll($this->server);

        self::assertTrue($this->state->allWorkersDrained(1));
        self::assertCount(1, iterator_to_array(Timer::list()), 'A request can still reach the worker after its report.');
        $drain->stop();
        self::assertSame([], iterator_to_array(Timer::list()));
    }

    /**
     * @template T of Server&Stub
     * @param T $server
     * @return T
     */
    private function serverDouble(Server&Stub $server): Server&Stub
    {
        $server->worker_id = 0;
        $server->taskworker = false;
        $server->method('stats')->willReturnCallback(fn (): array => [
            'worker_concurrency' => count($this->concurrency) > 1 ? array_shift($this->concurrency) : $this->concurrency[0],
        ]);
        $server->method('exist')->willReturnCallback(function (int $fd): bool {
            $answers = $this->exists[$fd] ?? [false];
            if (count($answers) > 1) {
                $this->exists[$fd] = array_slice($answers, 1);
            }

            return $answers[0];
        });

        return $server;
    }

    private function drain(?WorkerStreamAborterInterface $streams = null, ?LoggerInterface $logger = null): WorkerDrain
    {
        $pusher = new WebSocketPusher($this->registry, new JsonEncoder());
        $pusher->setServer($this->server);

        return new WorkerDrain(
            $this->state,
            new DrainBudget(10),
            $this->dispatcher,
            $logger ?? new NullLogger(),
            $pusher,
            $streams,
            monotonicNs: fn (): int => $this->nowNs,
        );
    }
}
