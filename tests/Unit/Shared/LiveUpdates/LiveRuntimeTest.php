<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveMessage;
use App\Shared\Application\LiveUpdates\LivePipelineHealth;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\LiveUpdates\LiveConnectionState;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as SwooleBridge;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Application\LiveUpdates\LiveChangeProjector;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\LiveUpdates\LiveUpdateRuntime;
use App\Shared\Infrastructure\LiveUpdates\LiveFrameSender;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\WebSocket\Server;

final class LiveRuntimeTest extends TestCase
{
    public function testPermissionResetPrecedesChangesAndReplacesTheRevisionBaseline(): void
    {
        $state = new LiveConnectionState(Uuid::v7());
        $first = $this->snapshot('a', '9007199254740992');
        self::assertSame('live.ready', $state->observe($first)[0]->toArray()['type']);
        $frames = $state->observe($this->snapshot('b', '9007199254740993'));
        self::assertSame(['live.reset'], array_map(static fn($frame) => $frame->toArray()['type'], $frames));
        self::assertSame('2', $frames[0]->toArray()['authorizationGeneration']);
        self::assertSame([], $state->observe($this->snapshot('b', '9007199254740993')));
        self::assertSame('live.changed', $state->observe($this->snapshot('b', '9007199254740994'))[0]->toArray()['type']);
    }

    public function testReadFailuresKeepBaselineAndReportDegradedUntilSuccessfulRead(): void
    {
        $state = new LiveConnectionState(Uuid::v7());
        $state->observe($this->snapshot('a', '1'));
        self::assertSame('degraded', $state->failed()?->toArray()['health']['state']);
        self::assertNull($state->failed());
        $frames = $state->observe($this->snapshot('a', '2'));
        self::assertSame('live.changed', $frames[0]->toArray()['type']);
        self::assertSame('current', $frames[0]->toArray()['health']['state']);
    }

    public function testBacklogHealthIsIndependentOfTransportLiveness(): void
    {
        $state = new LiveConnectionState(Uuid::v7());
        $snapshot = new LiveSnapshot([LiveFamily::cases()[0]], str_repeat('a', 64), [], new LivePipelineHealth(5001));
        self::assertSame('degraded', $state->observe($snapshot)[0]->toArray()['health']['state']);
    }

    public function testIndependentConnectionsHaveDistinctSubscriptionIdentities(): void
    {
        $principal = Uuid::v7();
        $one = new LiveConnectionState($principal);
        $two = new LiveConnectionState($principal);
        self::assertNotSame($one->observe($this->snapshot('a', '1'))[0]->toArray()['subscriptionId'], $two->observe($this->snapshot('a', '1'))[0]->toArray()['subscriptionId']);
    }

    public function testResetIncludesFamiliesRemovedFromCurrentAuthorization(): void
    {
        $state = new LiveConnectionState(Uuid::v7());
        $state->observe($this->snapshot('a', '1'));
        $reset = $state->observe(new LiveSnapshot([], str_repeat('b', 64), [], new LivePipelineHealth(null)))[0]->toArray();
        self::assertSame('live.reset', $reset['type']);
        self::assertSame([LiveFamily::cases()[0]->value], $reset['families']);
    }

    public function testProjectionFailureDegradesAnOtherwiseCurrentSnapshot(): void
    {
        $state = new LiveConnectionState(Uuid::v7());
        self::assertSame('degraded', $state->observe($this->snapshot('a', '1'), true)[0]->toArray()['health']['state']);
        self::assertSame('current', $state->observe($this->snapshot('a', '1'))[0]->toArray()['health']['state']);
    }

    public function testResumedOldSenderCannotCloseOrDiscardAReplacementSubscription(): void
    {
        $server = $this->createMock(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('getClientInfo')->willReturn(['send_queued_bytes' => 0]);
        $server->expects(self::never())->method('close');
        $calls = 0;
        $waiting = [];
        $server->method('push')->willReturnCallback(static function () use (&$calls, &$waiting): bool {
            $call = ++$calls;
            if ($call <= 2) {
                $waiting[] = Coroutine::getCid();
                Coroutine::suspend();
                return $call !== 1;
            }
            return true;
        });
        $sender = new LiveFrameSender(new NullLogger());
        $sender->setServer($server);
        $state = new LiveConnectionState(Uuid::v7());
        $frame = $state->observe($this->snapshot('a', '1'))[0];
        \Swoole\Coroutine\run(function () use ($sender, $frame, &$waiting): void {
            $sender->send(7, $frame);
            $sender->forget(7);
            $sender->send(7, $frame);
            $sender->send(7, $frame);
            Coroutine::resume($waiting[0]);
            Coroutine::resume($waiting[1]);
        });
        self::assertSame(3, $calls);
    }

    public function testCoalescedPermissionResetsRetainEveryFamilyThatNeedsPurging(): void
    {
        $server = $this->createStub(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('getClientInfo')->willReturn(['send_queued_bytes' => 0]);
        $frames = [];
        $waiting = 0;
        $server->method('push')->willReturnCallback(static function (int $fd, string $data) use (&$frames, &$waiting): bool {
            $frames[] = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
            if (count($frames) === 1) {
                $waiting = Coroutine::getCid();
                Coroutine::suspend();
            }
            return true;
        });
        $sender = new LiveFrameSender(new NullLogger());
        $sender->setServer($server);
        $state = new LiveConnectionState(Uuid::v7());
        $ready = $state->observe($this->snapshot('a', '1'))[0];
        $reset = $ready->toArray();
        $reset['type'] = 'live.reset';
        $reset['authorizationGeneration'] = '2';
        $reset['families'] = [LiveFamily::cases()[0]->value];
        $latest = $reset;
        $latest['authorizationGeneration'] = '3';
        $latest['families'] = [LiveFamily::cases()[1]->value];
        \Swoole\Coroutine\run(function () use ($sender, $ready, $reset, $latest, &$waiting): void {
            $sender->send(7, $ready);
            $sender->send(7, LiveMessage::fromArray($reset));
            $sender->send(7, LiveMessage::fromArray($latest));
            Coroutine::resume($waiting);
        });
        self::assertCount(2, $frames);
        self::assertSame('3', $frames[1]['authorizationGeneration']);
        self::assertSame([LiveFamily::cases()[0]->value, LiveFamily::cases()[1]->value], $frames[1]['families']);
    }

    public function testSnapshotPoolIsReleasedBeforeNativeDeliveryStarts(): void
    {
        $pool = new class implements ServicePool {
            public bool $assigned = false;
            public function get(): object
            {
                $this->assigned = true;
                return new \stdClass();
            }
            public function releaseFromCoroutine(int $cId): void
            {
                $this->assigned = false;
            }
            public function getAssignedCount(): int
            {
                return (int) $this->assigned;
            }
            public function getFreeCount(): int
            {
                return (int) !$this->assigned;
            }
            public function getInstancesLimit(): int
            {
                return 1;
            }
        };
        $pools = new ServicePoolContainer([[$pool]]);
        $wrapper = new CoWrapper($pools, new SwooleBridge());
        $reader = $this->createStub(LiveSnapshotReader::class);
        $reader->method('read')->willReturnCallback(function () use ($pool): LiveSnapshot {
            $pool->get();
            return $this->snapshot('a', '1');
        });
        $server = $this->createMock(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->method('getClientInfo')->willReturn(['send_queued_bytes' => 0]);
        $server->expects(self::once())->method('push')->willReturnCallback(static function () use ($pool): bool {
            self::assertFalse($pool->assigned);
            return true;
        });
        $sender = new LiveFrameSender(new NullLogger());
        $sender->setServer($server);
        $registry = WebSocketConnectionRegistry::create(32, 64);
        $principal = Uuid::v7();
        $registry->addConnection(7, $principal->toString(), 0, 'runtime-token');
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn(false);
        $runtime = new LiveUpdateRuntime($reader, $this->createStub(LiveChangeProjector::class), $registry, $sender, new NullLogger(), $wrapper, $drain, $pools);
        \Swoole\Coroutine\run(static function () use ($wrapper, $runtime, $principal): void {
            $wrapper->defer();
            $runtime->subscribe(7, $principal);
        });
        self::assertFalse($pool->assigned);
    }

    private function snapshot(string $fingerprint, string $revision): LiveSnapshot
    {
        $family = LiveFamily::cases()[0];
        return new LiveSnapshot([$family], str_repeat($fingerprint, 64), [$family->value . ':user:principal' => $revision], new LivePipelineHealth(null));
    }
}
