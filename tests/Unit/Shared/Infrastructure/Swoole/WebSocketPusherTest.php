<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebSocketPusherTest extends TestCase
{
    private Server&MockObject $server;
    private WebSocketConnectionRegistry $registry;
    private WebSocketPusher $pusher;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded.');
        }

        $this->server = $this->createMock(Server::class);
        $this->registry = WebSocketConnectionRegistry::create(
            maxConnections: 64,
            maxRoomMembers: 256,
        );
        $this->pusher = new WebSocketPusher($this->registry, new JsonEncoder());
        $this->pusher->setServer($this->server);
    }

    // --- push to user ---

    public function testPushToUserWithSingleConnection(): void
    {
        $this->registry->addConnection(10, 'user-1', 0);

        $this->server->method('isEstablished')->with(10)->willReturn(true);
        $this->server->expects($this->once())
            ->method('push')
            ->with(10, '{"type":"test","data":"hello"}')
            ->willReturn(true);

        $sent = $this->pusher->push('user-1', ['type' => 'test', 'data' => 'hello']);
        $this->assertSame(1, $sent);
    }

    public function testPushToUserWithMultipleConnections(): void
    {
        $this->registry->addConnection(10, 'user-1', 0);
        $this->registry->addConnection(20, 'user-1', 0);
        $this->registry->addConnection(30, 'user-1', 0);

        $this->server->method('isEstablished')->willReturn(true);
        $this->server->expects($this->exactly(3))
            ->method('push')
            ->willReturn(true);

        $sent = $this->pusher->push('user-1', ['type' => 'test']);
        $this->assertSame(3, $sent);
    }

    public function testPushToNonExistentUserReturnsZero(): void
    {
        $this->server->expects($this->never())->method('push');

        $sent = $this->pusher->push('unknown', ['type' => 'test']);
        $this->assertSame(0, $sent);
    }

    public function testPushToUserSkipsClosedConnections(): void
    {
        $this->registry->addConnection(10, 'user-1', 0);
        $this->registry->addConnection(20, 'user-1', 0);

        $this->server->method('isEstablished')
            ->willReturnMap([[10, true], [20, false]]);
        $this->server->expects($this->once())
            ->method('push')
            ->with(10, $this->callback(fn($v) => is_string($v)))
            ->willReturn(true);

        $sent = $this->pusher->push('user-1', ['type' => 'test']);
        $this->assertSame(1, $sent);
    }

    // --- push to connection ---

    public function testPushToConnectionWithArrayPayload(): void
    {
        $this->server->method('isEstablished')->with(42)->willReturn(true);
        $this->server->expects($this->once())
            ->method('push')
            ->with(42, '{"type":"direct"}')
            ->willReturn(true);

        $result = $this->pusher->pushToConnection(42, ['type' => 'direct']);
        $this->assertTrue($result);
    }

    public function testPushToConnectionWithStringPayload(): void
    {
        $this->server->method('isEstablished')->with(42)->willReturn(true);
        $this->server->expects($this->once())
            ->method('push')
            ->with(42, 'raw-string-payload')
            ->willReturn(true);

        $result = $this->pusher->pushToConnection(42, 'raw-string-payload');
        $this->assertTrue($result);
    }

    public function testPushToClosedConnectionReturnsFalse(): void
    {
        $this->server->method('isEstablished')->with(99)->willReturn(false);
        $this->server->expects($this->never())->method('push');

        $result = $this->pusher->pushToConnection(99, ['type' => 'test']);
        $this->assertFalse($result);
    }

    // --- broadcast ---

    public function testBroadcastToRoom(): void
    {
        $this->registry->addConnection(10, 'user-a', 0);
        $this->registry->addConnection(20, 'user-b', 0);
        $this->registry->joinRoom('party:abc', 10);
        $this->registry->joinRoom('party:abc', 20);

        $this->server->method('isEstablished')->willReturn(true);
        $this->server->expects($this->exactly(2))
            ->method('push')
            ->with($this->callback(fn($v) => is_int($v)), '{"type":"playback","position":42}')
            ->willReturn(true);

        $sent = $this->pusher->broadcast('party:abc', ['type' => 'playback', 'position' => 42]);
        $this->assertSame(2, $sent);
    }

    public function testBroadcastToEmptyRoomReturnsZero(): void
    {
        $this->server->expects($this->never())->method('push');

        $sent = $this->pusher->broadcast('empty-room', ['type' => 'test']);
        $this->assertSame(0, $sent);
    }

    public function testBroadcastSkipsDisconnectedMembers(): void
    {
        $this->registry->addConnection(10, 'user-a', 0);
        $this->registry->addConnection(20, 'user-b', 0);
        $this->registry->addConnection(30, 'user-c', 0);
        $this->registry->joinRoom('party:abc', 10);
        $this->registry->joinRoom('party:abc', 20);
        $this->registry->joinRoom('party:abc', 30);

        $this->server->method('isEstablished')
            ->willReturnMap([[10, true], [20, false], [30, true]]);
        $this->server->expects($this->exactly(2))
            ->method('push')
            ->willReturn(true);

        $sent = $this->pusher->broadcast('party:abc', ['type' => 'test']);
        $this->assertSame(2, $sent);
    }
}
