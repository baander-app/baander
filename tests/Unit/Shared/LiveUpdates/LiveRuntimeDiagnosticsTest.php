<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveChangeProjector;
use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LivePipelineHealth;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\LiveUpdates\LiveConnectionState;
use App\Shared\Infrastructure\LiveUpdates\LiveFrameSender;
use App\Shared\Infrastructure\LiveUpdates\LiveUpdateRuntime;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugStatsOperation;
use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as SwooleBridge;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;

final class LiveRuntimeDiagnosticsTest extends TestCase
{
    private bool $failRead = false;
    private bool $failProjection = true;
    private ?int $pendingAge = 42;
    private bool $overloaded = false;

    public function testDebugOperationReportsUnknownBacklogWithoutTouchingDatabasePorts(): void
    {
        if (!function_exists('posix_getuid')) {
            self::markTestSkipped('Worker process diagnostics require POSIX.');
        }
        $reader = $this->createMock(LiveSnapshotReader::class);
        $reader->expects(self::never())->method('read');
        $projector = $this->createMock(LiveChangeProjector::class);
        $projector->expects(self::never())->method('project');
        [$runtime] = $this->runtime($reader, $projector);
        $operation = new DebugStatsOperation(new SwoolePoolStatsProvider(new ServicePoolContainer([])), $runtime);
        self::assertSame([
            'projector_enabled' => false,
            'last_successful_projection_check_age_ms' => null,
            'last_successful_reconciliation_age_ms' => null,
            'max_subscription_read_age_ms' => null,
            'backlog_observed' => false,
            'last_observed_oldest_pending_age_ms' => null,
            'projection_error_count' => 0,
            'read_error_count' => 0,
            'active_subscriptions' => 0,
            'pending_send_count' => 0,
            'slow_peer_close_count' => 0,
        ], $operation->handle([])['live_updates']);
    }

    public function testProgressAndFailuresRemainVisibleWithoutInventingCurrentBacklog(): void
    {
        $reader = $this->createStub(LiveSnapshotReader::class);
        $reader->method('read')->willReturnCallback(function (): LiveSnapshot {
            if ($this->failRead) {
                throw new \RuntimeException('Unavailable revision read.');
            }
            return new LiveSnapshot([LiveFamily::Catalog], str_repeat('a', 64), ['catalog:user:principal' => '1'], new LivePipelineHealth($this->pendingAge));
        });
        $projector = $this->createStub(LiveChangeProjector::class);
        $projector->method('project')->willReturnCallback(function (): int {
            if ($this->failProjection) {
                throw new \RuntimeException('Unavailable projection.');
            }
            return 0;
        });
        [$runtime, , $registry] = $this->runtime($reader, $projector);
        self::assertSame(0, $runtime->diagnostics()['read_error_count']);
        $principal = Uuid::v7();
        $registry->addConnection(7, $principal->toString(), 0, 'runtime-token');
        \Swoole\Coroutine\run(function () use ($runtime, $principal): void {
            $runtime->subscribe(7, $principal);
            $success = $runtime->diagnostics();
            self::assertSame(1, $success['active_subscriptions']);
            self::assertTrue($success['backlog_observed']);
            self::assertSame(42, $success['last_observed_oldest_pending_age_ms']);
            self::assertIsInt($success['last_successful_reconciliation_age_ms']);
            self::assertIsInt($success['max_subscription_read_age_ms']);
            $this->failRead = true;
            $runtime->reconcile();
            $runtime->project();
            $failure = $runtime->diagnostics();
            self::assertSame(1, $failure['read_error_count']);
            self::assertSame(1, $failure['projection_error_count']);
            self::assertNull($failure['last_successful_projection_check_age_ms']);
            self::assertSame(42, $failure['last_observed_oldest_pending_age_ms']);
            $this->failRead = $this->failProjection = false;
            $this->pendingAge = null;
            $runtime->project();
            $runtime->reconcile();
            $recovered = $runtime->diagnostics();
            self::assertIsInt($recovered['last_successful_projection_check_age_ms']);
            self::assertSame(1, $recovered['projection_error_count']);
            self::assertSame(1, $recovered['read_error_count']);
            self::assertTrue($recovered['backlog_observed']);
            self::assertNull($recovered['last_observed_oldest_pending_age_ms']);
            $runtime->stop();
            self::assertSame(0, $runtime->diagnostics()['active_subscriptions']);
        });
    }

    public function testProjectorEnabledTracksItsWorkerTimerLifecycle(): void
    {
        [$runtime] = $this->runtime($this->createStub(LiveSnapshotReader::class), $this->createStub(LiveChangeProjector::class));
        $server = $this->createStub(Server::class);
        \Swoole\Coroutine\run(static function () use ($runtime, $server): void {
            $runtime->onWorkerStarted(new WorkerStartedEvent($server, 0));
            self::assertTrue($runtime->diagnostics()['projector_enabled']);
            $runtime->stop();
            self::assertFalse($runtime->diagnostics()['projector_enabled']);
            $runtime->onWorkerStarted(new WorkerStartedEvent($server, 1));
            self::assertFalse($runtime->diagnostics()['projector_enabled']);
            $runtime->stop();
        });
    }

    public function testSenderDiagnosticsCountCoalescedPendingWorkAndOverloadCloses(): void
    {
        $server = $this->createStub(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('getClientInfo')->willReturnCallback(function (): array {
            return ['send_queued_bytes' => $this->overloaded ? 65536 : 0];
        });
        $waiting = 0;
        $calls = 0;
        $server->method('push')->willReturnCallback(static function () use (&$waiting, &$calls): bool {
            if (++$calls === 1) {
                $waiting = Coroutine::getCid();
                Coroutine::suspend();
            }
            return true;
        });
        $sender = new LiveFrameSender(new NullLogger());
        $sender->setServer($server);
        $state = new LiveConnectionState(Uuid::v7());
        $frame = $state->observe(new LiveSnapshot([LiveFamily::Catalog], str_repeat('a', 64), [], new LivePipelineHealth(null)))[0];
        \Swoole\Coroutine\run(function () use ($sender, $frame, &$waiting): void {
            $sender->send(7, $frame);
            $sender->send(7, $frame);
            $sender->send(7, $frame);
            try {
                self::assertSame(1, $sender->diagnostics()['pending_send_count']);
            } finally {
                Coroutine::resume($waiting);
            }
            self::assertSame(0, $sender->diagnostics()['pending_send_count']);
            $this->overloaded = true;
            $sender->send(7, $frame);
            self::assertSame(1, $sender->diagnostics()['slow_peer_close_count']);
            $sender->close(7, 1013, 'Live delivery unavailable');
            self::assertSame(1, $sender->diagnostics()['slow_peer_close_count']);
            $sender->close(7, 1008, 'Authorization ended');
            self::assertSame(1, $sender->diagnostics()['slow_peer_close_count']);
        });
    }

    /** @return array{LiveUpdateRuntime, LiveFrameSender, WebSocketConnectionRegistry} */
    private function runtime(LiveSnapshotReader $reader, LiveChangeProjector $projector): array
    {
        $pools = new ServicePoolContainer([]);
        $wrapper = new CoWrapper($pools, new SwooleBridge());
        $sender = new LiveFrameSender(new NullLogger());
        $server = $this->createStub(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('getClientInfo')->willReturn(['send_queued_bytes' => 0]);
        $server->method('push')->willReturn(true);
        $sender->setServer($server);
        $registry = WebSocketConnectionRegistry::create(32, 64);
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn(false);
        return [new LiveUpdateRuntime($reader, $projector, $registry, $sender, new NullLogger(), $wrapper, $drain, $pools), $sender, $registry];
    }
}
