<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Swoole;

use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Infrastructure\Swoole\RevokedSocketReconciler;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swoole\Event;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;

/**
 * The reconciliation tick of HTTP worker 0: its timer lifecycle, and one run that closes
 * the connections whose access token the database no longer calls live.
 */
final class RevokedSocketReconcilerTest extends TestCase
{
    private WebSocketConnectionRegistry $registry;
    private ReconcilerRecordingLogger $logger;
    /** @var list<list<string>> the ids each findLiveTokenIds() call asked about */
    private array $asked = [];
    /** @var list<string> what the repository answers */
    private array $live = [];
    /** Called from inside the repository, while a tick is running. */
    private ?\Closure $duringQuery = null;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('The reconciler timer requires Swoole.');
        }
        $this->registry = WebSocketConnectionRegistry::create(maxConnections: 64, maxRoomMembers: 64);
        $this->logger = new ReconcilerRecordingLogger();
    }

    protected function tearDown(): void
    {
        Timer::clearAll();
        // With no timer left this returns at once and frees the event loop; otherwise Swoole
        // runs the loop itself when the process ends, with a deprecation notice.
        Event::wait();
    }

    // --- Lifecycle ---

    public function testHttpWorkerZeroKeepsATimerUntilItStopsAndStartsItAgainOnTheNextStart(): void
    {
        $reconciler = $this->reconciler();
        $server = self::server();

        $reconciler->onWorkerStarted(new WorkerStartedEvent($server, 0));
        self::assertSame(1, Timer::stats()['num']);
        // The first run waits one interval; nothing has been asked yet.
        self::assertSame([], $this->asked);

        $reconciler->onWorkerStopped(new WorkerStoppedEvent($server, 0));
        self::assertSame(0, Timer::stats()['num']);

        $reconciler->onWorkerStarted(new WorkerStartedEvent($server, 0));
        self::assertSame(1, Timer::stats()['num']);
    }

    public function testATaskWorkerStartsNoTimer(): void
    {
        $server = self::server();
        $server->taskworker = true;

        $this->reconciler()->onWorkerStarted(new WorkerStartedEvent($server, 0));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testHttpWorkerOneStartsNoTimer(): void
    {
        $this->reconciler()->onWorkerStarted(new WorkerStartedEvent(self::server(), 1));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testStopClearsTheTimerOnceAndOnWorkerStoppedAfterItDoesNothing(): void
    {
        $reconciler = $this->reconciler();
        $server = self::server();
        $reconciler->onWorkerStarted(new WorkerStartedEvent($server, 0));

        $reconciler->stop();
        $reconciler->stop();
        $reconciler->onWorkerStopped(new WorkerStoppedEvent($server, 0));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testWhileTheServerDrainsATickAsksAndClosesNothing(): void
    {
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn(true);
        $this->registry->addConnection(1, 'user-1', 0, 'token-dead');
        $connections = $this->createMock(LiveConnectionsPortInterface::class);
        $connections->expects(self::never())->method('closeForAccessTokens');

        $this->reconciler($connections, $drain)->tick();

        self::assertSame([], $this->asked);
        self::assertSame([], $this->logger->records);
    }

    public function testATickThatStartsWhileThePreviousTickStillRunsSkipsAndWarns(): void
    {
        $this->registry->addConnection(1, 'user-1', 0, 'token-live');
        $this->live = ['token-live'];
        $reconciler = $this->reconciler();
        $this->duringQuery = static function () use ($reconciler): void {
            $reconciler->tick();
        };

        $reconciler->tick();

        self::assertCount(1, $this->asked);
        self::assertSame(['warning'], array_column($this->logger->records, 0));
        self::assertStringContainsString('previous tick', $this->logger->records[0][1]);

        // Once the running tick has finished, the next one runs again.
        $reconciler->tick();
        self::assertCount(2, $this->asked);
        self::assertCount(1, $this->logger->records);
    }

    // --- One run ---

    public function testTheDeadTokensAreHandedToTheClosePortOnceAndALiveOneIsLeftAlone(): void
    {
        $this->registry->addConnection(1, 'user-1', 0, 'token-revoked');
        $this->registry->addConnection(2, 'user-1', 1, 'token-revoked');
        $this->registry->addConnection(3, 'user-2', 0, 'token-expired');
        $this->registry->addConnection(4, 'user-3', 0, 'token-live');
        $this->live = ['token-live'];
        $connections = $this->createMock(LiveConnectionsPortInterface::class);
        $connections->expects(self::once())->method('closeForAccessTokens')
            ->with(self::callback(static function (array $ids): bool {
                sort($ids);

                return $ids === ['token-expired', 'token-revoked'];
            }))
            ->willReturn(3);

        $this->reconciler($connections)->tick();

        self::assertCount(1, $this->asked);
        $asked = $this->asked[0];
        sort($asked);
        self::assertSame(['token-expired', 'token-live', 'token-revoked'], $asked);
        self::assertSame([], $this->logger->records);
    }

    public function testNothingDeadMeansNoClose(): void
    {
        $this->registry->addConnection(1, 'user-1', 0, 'token-live');
        $this->live = ['token-live'];
        $connections = $this->createMock(LiveConnectionsPortInterface::class);
        $connections->expects(self::never())->method('closeForAccessTokens');

        $this->reconciler($connections)->tick();

        self::assertSame([['token-live']], $this->asked);
        self::assertSame([], $this->logger->records);
    }

    public function testNoConnectionsMeansNoQuery(): void
    {
        $connections = $this->createMock(LiveConnectionsPortInterface::class);
        $connections->expects(self::never())->method('closeForAccessTokens');

        $this->reconciler($connections)->tick();

        self::assertSame([], $this->asked);
    }

    public function testACloseThatReportsFewerConnectionsThanListedLogsAnError(): void
    {
        $this->registry->addConnection(1, 'user-1', 0, 'token-revoked');
        $this->registry->addConnection(2, 'user-1', 1, 'token-revoked');
        $this->registry->addConnection(3, 'user-3', 0, 'token-live');
        $this->live = ['token-live'];
        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willReturn(1);

        $this->reconciler($connections)->tick();

        self::assertSame(['error'], array_column($this->logger->records, 0));
        [, $message, $context] = $this->logger->records[0];
        self::assertStringContainsString('next tick', $message);
        self::assertSame(1, $context['count']);
        self::assertArrayNotHasKey('access_token_ids', $context);
        self::assertSame(2, $context['expected']);
        self::assertSame(1, $context['closed']);
    }

    public function testAControlChannelFailureLogsAnErrorAndTheTickEndsNormally(): void
    {
        $this->registry->addConnection(1, 'user-1', 0, 'token-revoked');
        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willThrowException(new ServerControlException('a worker did not answer'));
        $reconciler = $this->reconciler($connections);

        $reconciler->tick();

        self::assertSame(['error'], array_column($this->logger->records, 0));
        [, $message, $context] = $this->logger->records[0];
        self::assertStringContainsString('next tick', $message);
        self::assertSame(1, $context['count']);
        self::assertArrayNotHasKey('access_token_ids', $context);
        self::assertInstanceOf(ServerControlException::class, $context['exception']);

        // The failed tick released the reconciler for the next one.
        $reconciler->tick();
        self::assertCount(2, $this->asked);
        self::assertCount(2, $this->logger->records);
    }

    public function testNoWebServerInThisProcessIsLoggedAsAWarning(): void
    {
        $this->registry->addConnection(1, 'user-1', 0, 'token-revoked');
        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willThrowException(new ServerNotRunningException());

        $this->reconciler($connections)->tick();

        self::assertSame(['warning'], array_column($this->logger->records, 0));
        self::assertSame(1, $this->logger->records[0][2]['count']);
        self::assertStringNotContainsString('token-revoked', json_encode($this->logger->records, JSON_THROW_ON_ERROR), 'A log never carries a token id.');
    }

    private function reconciler(
        ?LiveConnectionsPortInterface $connections = null,
        ?ServerDrainStateInterface $drain = null,
    ): RevokedSocketReconciler {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findLiveTokenIds')->willReturnCallback(function (array $tokenIds): array {
            $this->asked[] = $tokenIds;
            $during = $this->duringQuery;
            $this->duringQuery = null;
            if ($during !== null) {
                $during();
            }

            return array_values(array_intersect($this->live, $tokenIds));
        });

        return new RevokedSocketReconciler(
            $this->registry,
            $accessTokens,
            $connections ?? $this->createStub(LiveConnectionsPortInterface::class),
            $this->logger,
            60,
            drain: $drain,
        );
    }

    /** The events only need an HTTP worker's server object; a process may construct one Server. */
    private static function server(): Server
    {
        $server = (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
        $server->taskworker = false;

        return $server;
    }
}

final class ReconcilerRecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    /** @param mixed[] $context */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
