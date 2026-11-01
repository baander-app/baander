<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Infrastructure\Swoole;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Infrastructure\Swoole\PlaybackCommandRelay;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Event;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as SwooleBridge;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PlaybackCommandRelayTest extends TestCase
{
    protected function tearDown(): void
    {
        Timer::clearAll();
        Event::wait();
    }

    public function testCommittedCommandsReplayWithoutClientPollingAndFailedPushRetries(): void
    {
        $owner = new Uuid();
        $incarnation = new Uuid()->toString();
        $snapshot = ['revision' => 7, 'commands' => [['id' => new Uuid()->toString()]]];
        $playback = $this->createMock(PlaybackCoordinationInterface::class);
        $requested = [];
        $playback->expects(self::exactly(2))->method('execute')->willReturnCallback(static function (...$arguments) use (&$requested, $snapshot): array {
            $requested[] = $arguments;
            return $snapshot;
        });
        $frames = [];
        $server = $this->createMock(Server::class);
        $server->method('isEstablished')->willReturn(true);
        $server->expects(self::exactly(2))->method('push')->willReturnCallback(static function (int $fd, string $data) use (&$frames): bool {
            $frames[] = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return count($frames) > 1;
        });
        $relay = $this->relay($playback, $server);
        $relay->bind(7, $owner, $incarnation, 'endpoint-secret');
        \Swoole\Coroutine\run(static function () use ($relay): void {
            $relay->tick();
            $relay->tick();
        });
        $relay->unbind(7);
        \Swoole\Coroutine\run($relay->tick(...));
        self::assertSame([$owner, PlaybackOperation::Snapshot, ['incarnationId' => $incarnation], 'endpoint-secret'], $requested[0]);
        self::assertSame($frames[0], $frames[1]);
        self::assertSame(['type' => 'session.snapshot', 'data' => $snapshot], $frames[1]);
        self::assertStringNotContainsString('endpoint-secret', json_encode($frames, JSON_THROW_ON_ERROR));
    }

    public function testEveryHttpWorkerOwnsOneTimerAndDrainingStopsReads(): void
    {
        $server = $this->createStub(Server::class);
        $server->taskworker = false;
        $playback = $this->createMock(PlaybackCoordinationInterface::class);
        $playback->expects(self::never())->method('execute');
        $relay = $this->relay($playback, $server, true);
        $relay->bind(1, new Uuid());
        $relay->onWorkerStarted(new WorkerStartedEvent($server, 2));
        $relay->onWorkerStarted(new WorkerStartedEvent($server, 2));
        self::assertSame(1, Timer::stats()['num']);
        \Swoole\Coroutine\run(static function () use ($relay, $server): void {
            $relay->tick();
            $relay->onWorkerStopped(new WorkerStoppedEvent($server, 2));
        });
        $relay->stop();
        self::assertSame(0, Timer::stats()['num']);

    }

    public function testReplacementBindingCannotReceiveOrRecordPreviousOwnersSnapshot(): void
    {
        $first = new Uuid();
        $replacement = new Uuid();
        $playback = $this->createMock(PlaybackCoordinationInterface::class);
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('push');
        $relay = $this->relay($playback, $server);
        $playback->expects(self::once())->method('execute')->willReturnCallback(static function () use ($relay, $replacement): array {
            $relay->unbind(7);
            $relay->bind(7, $replacement);
            return ['revision' => 7, 'commands' => []];
        });
        $relay->bind(7, $first, new Uuid()->toString(), 'old-secret');
        \Swoole\Coroutine\run($relay->tick(...));
    }

    public function testTaskWorkerStartsNoReplayTimer(): void
    {
        $server = $this->createStub(Server::class);
        $server->taskworker = true;
        $relay = $this->relay($this->createStub(PlaybackCoordinationInterface::class), $server);
        $relay->onWorkerStarted(new WorkerStartedEvent($server, 2));
        self::assertSame(0, Timer::stats()['num']);
    }

    private function relay(PlaybackCoordinationInterface $playback, Server $server, bool $draining = false): PlaybackCommandRelay
    {
        $pusher = new WebSocketPusher(WebSocketConnectionRegistry::create(32, 64), new JsonEncoder());
        $pusher->setServer($server);
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn($draining);
        return new PlaybackCommandRelay($playback, $pusher, new CoWrapper(new ServicePoolContainer([]), new SwooleBridge()), $drain, new NullLogger());
    }
}
