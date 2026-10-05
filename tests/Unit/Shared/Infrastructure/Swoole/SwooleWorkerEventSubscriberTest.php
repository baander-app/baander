<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SwooleWorkerEventSubscriberTest extends TestCase
{
    public function testStartupRecordsTheEventAndInitializesWebSocketServices(): void
    {
        $buffer = new SwooleWorkerEventBuffer();
        $registry = WebSocketConnectionRegistry::create(16, 16);
        $pusher = new WebSocketPusher($registry, new JsonEncoder());
        $subscriber = new SwooleWorkerEventSubscriber($buffer, webSocketPusher: $pusher, webSocketRegistry: $registry);
        $server = new Server('127.0.0.1', 0);
        $server->worker_id = 3;

        $subscriber->onWorkerStarted(new WorkerStartedEvent($server, 3));

        self::assertSame(3, $registry->getWorkerId());
        self::assertSame($server, (new \ReflectionProperty($pusher, 'server'))->getValue($pusher));
        self::assertSame(['started'], array_column($buffer->getAll(), 'type'));
        self::assertSame([3], array_column($buffer->getAll(), 'workerId'));
    }

    public function testWorkerStopDisposesTheRedisFactoryAndRecordsTheEvent(): void
    {
        $buffer = new SwooleWorkerEventBuffer();
        $factory = $this->createMock(RedisClientFactory::class);
        $factory->expects(self::once())->method('dispose');
        $subscriber = new SwooleWorkerEventSubscriber($buffer, redisClientFactory: $factory);

        $subscriber->onWorkerStopped(new WorkerStoppedEvent(new Server('127.0.0.1', 0), 3));

        self::assertSame(['stopped'], array_column($buffer->getAll(), 'type'));
        self::assertSame([3], array_column($buffer->getAll(), 'workerId'));
    }

    public function testWorkerStopWorksWithoutTheOptionalRedisFactory(): void
    {
        $buffer = new SwooleWorkerEventBuffer();
        $subscriber = new SwooleWorkerEventSubscriber($buffer);

        $subscriber->onWorkerStopped(new WorkerStoppedEvent(new Server('127.0.0.1', 0), 0));

        self::assertSame(['stopped'], array_column($buffer->getAll(), 'type'));
    }
}
