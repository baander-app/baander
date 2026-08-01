<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Interface\Controller\WebSocketController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebSocketControllerTest extends TestCase
{
    private WebSocketConnectionRegistry $registry;
    private WebSocketPusher $pusher;
    private Server&MockObject $server;
    private MessageBusInterface&MockObject $bus;
    private ReconnectionTokenService $reconnectionTokens;
    private WebSocketController $controller;

    /** @var list<array{fd: int, data: string}> Captured push calls from the mock server. */
    private array $pushedMessages = [];

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded.');
        }

        $this->registry = WebSocketConnectionRegistry::create(
            maxConnections: 64,
            maxRoomMembers: 256,
        );

        $this->pushedMessages = [];
        $this->server = $this->createMock(Server::class);
        $this->server->worker_id = 0;

        $pushedMessages = &$this->pushedMessages;
        $this->server->method('isEstablished')->willReturn(true);
        $this->server->method('push')->willReturnCallback(
            function (int $fd, string $data) use (&$pushedMessages): bool {
                $pushedMessages[] = ['fd' => $fd, 'data' => $data];

                return true;
            },
        );

        $this->pusher = new WebSocketPusher($this->registry, new JsonEncoder());
        $this->pusher->setServer($this->server);

        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->reconnectionTokens = ReconnectionTokenService::create(maxTokens: 64);

        $this->controller = new WebSocketController(
            $this->registry,
            $this->pusher,
            $this->bus,
            new JsonEncoder(),
            $this->reconnectionTokens,
        );
    }

    private function lastPushedPayload(): ?array
    {
        $last = end($this->pushedMessages);
        if ($last === false) {
            return null;
        }

        return json_decode($last['data'], true);
    }

    private function allPushedPayloads(): array
    {
        return array_map(fn (array $m): array => [
            'fd' => $m['fd'],
            'payload' => json_decode($m['data'], true),
        ], $this->pushedMessages);
    }

    private function assertLastPushMatches(int $fd, string $type, array $extra = []): void
    {
        $payload = $this->lastPushedPayload();
        $this->assertNotNull($payload, 'No message was pushed.');
        $lastMessage = end($this->pushedMessages);
        $this->assertSame($fd, $lastMessage['fd']);
        $this->assertSame($type, $payload['type']);
        foreach ($extra as $key => $value) {
            $this->assertSame($value, $payload[$key], "Payload field '{$key}' mismatch.");
        }
    }

    // --- onOpen ---

    public function testOnOpenStoresConnectionInRegistry(): void
    {
        $this->controller->onOpen(1, 'user-uuid-1');

        $conn = $this->registry->getConnection(1);
        $this->assertNotNull($conn);
        $this->assertSame('user-uuid-1', $conn['user_id']);
        $this->assertSame(0, $conn['worker_id']);
    }

    public function testOnOpenStoresConnectionWithCorrectWorkerId(): void
    {
        $this->registry->setWorkerId(1);

        $controller = new WebSocketController(
            $this->registry,
            $this->pusher,
            $this->bus,
            new JsonEncoder(),
            $this->reconnectionTokens,
        );

        $controller->onOpen(5, 'user-uuid-2');

        $conn = $this->registry->getConnection(5);
        $this->assertNotNull($conn);
        $this->assertSame(1, $conn['worker_id']);
    }

    public function testOnOpenSendsConnectedMessageWithReconnectToken(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->assertLastPushMatches(1, 'connected');
        $payload = $this->lastPushedPayload();
        $this->assertArrayHasKey('reconnectToken', $payload);
        $this->assertIsString($payload['reconnectToken']);
        $this->assertGreaterThan(0, strlen($payload['reconnectToken']));
    }

    // --- onMessage ---

    public function testOnMessageWithPingSendsPong(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode(['type' => 'ping']));

        // First push is connected, second is pong
        $all = $this->allPushedPayloads();
        $this->assertCount(2, $all);
        $this->assertSame('connected', $all[0]['payload']['type']);
        $this->assertSame('pong', $all[1]['payload']['type']);
    }

    public function testOnMessageWithInvalidJsonSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, 'not-json{{{');

        $payload = $this->lastPushedPayload();
        $this->assertNotNull($payload);
        $this->assertSame('error', $payload['type']);
        $this->assertSame('Invalid JSON', $payload['message']);
    }

    public function testOnMessageWithMissingTypeSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode(['data' => 'hello']));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Invalid message format: must be JSON with a "type" field']);
    }

    public function testOnMessageWithUnknownTypeSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode(['type' => 'bogus.type']));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Unknown message type: "bogus.type"']);
    }

    public function testOnMessageWithEmptyStringSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, '');

        $payload = $this->lastPushedPayload();
        $this->assertNotNull($payload);
        $this->assertSame('error', $payload['type']);
    }

    public function testOnMessageWithoutOpenSendsAuthError(): void
    {
        // No onOpen called — userId not tracked
        $this->controller->onMessage(1, json_encode(['type' => 'ping']));

        $payload = $this->lastPushedPayload();
        $this->assertNotNull($payload);
        $this->assertSame('error', $payload['type']);
        $this->assertSame('Not authenticated', $payload['message']);
    }

    // --- Room join/leave ---

    public function testRoomJoinStoresMembership(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode([
            'type' => 'room.join',
            'room' => 'notifications:user-1',
        ]));

        $this->assertSame([1], $this->registry->getRoomMembers('notifications:user-1'));
        $this->assertLastPushMatches(1, 'room.joined', ['room' => 'notifications:user-1']);
    }

    public function testRoomJoinWithoutRoomFieldSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode(['type' => 'room.join']));

        $this->assertLastPushMatches(1, 'error', ['message' => 'room.join requires a non-empty "room" field']);
    }

    public function testRoomLeaveRemovesMembership(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $this->controller->onMessage(1, json_encode([
            'type' => 'room.join',
            'room' => 'notifications:user-1',
        ]));

        $this->controller->onMessage(1, json_encode([
            'type' => 'room.leave',
            'room' => 'notifications:user-1',
        ]));

        $this->assertSame([], $this->registry->getRoomMembers('notifications:user-1'));
        $this->assertLastPushMatches(1, 'room.left', ['room' => 'notifications:user-1']);
    }

    public function testRoomLeaveNotJoinedDoesNotCrash(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode([
            'type' => 'room.leave',
            'room' => 'nonexistent',
        ]));

        $this->assertLastPushMatches(1, 'room.left', ['room' => 'nonexistent']);
    }

    // --- Party messages ---

    public function testPartyJoinWithoutSessionIdSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode(['type' => 'party.join']));

        $this->assertLastPushMatches(1, 'error', ['message' => 'party.join requires a "sessionId" field']);
    }

    public function testPartyJoinWithInvalidUuidSendsError(): void
    {
        $this->controller->onOpen(1, 'user-1');

        $this->controller->onMessage(1, json_encode([
            'type' => 'party.join',
            'sessionId' => 'not-a-uuid',
        ]));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Invalid UUID format']);
    }

    // --- Rate limiting ---

    public function testRateLimitTriggersAfterMaxMessagesPerSecond(): void
    {
        $this->controller->onOpen(1, 'user-1');

        for ($i = 0; $i < 30; ++$i) {
            $this->controller->onMessage(1, json_encode(['type' => 'ping']));
        }

        $all = $this->allPushedPayloads();
        $pongCount = array_filter($all, fn (array $m): bool => ($m['payload'] ?? null)['type'] === 'pong');
        $this->assertCount(30, $pongCount);

        $this->controller->onMessage(1, json_encode(['type' => 'ping']));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Rate limit exceeded']);
        // 1 (connected) + 30 (pong) + 1 (rate limit error) = 32
        $this->assertCount(32, $this->pushedMessages);
    }

    // --- onClose ---

    public function testOnCloseRemovesConnectionFromRegistry(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $this->assertNotNull($this->registry->getConnection(1));

        $this->controller->onClose(1);

        $this->assertNull($this->registry->getConnection(1));
    }

    public function testOnCloseDoesNotThrowForUnknownFd(): void
    {
        $this->controller->onClose(999);
        $this->assertNull($this->registry->getConnection(999));
    }

    public function testOnCloseCleansUpRoomMemberships(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $this->registry->joinRoom('room:123', 1);

        $this->assertSame([1], $this->registry->getRoomMembers('room:123'));

        $this->controller->onClose(1);

        $this->assertNull($this->registry->getConnection(1));
        $this->assertSame([], $this->registry->getRoomMembers('room:123'));
    }

    // --- Reconnection ---

    public function testAuthReconnectWithValidTokenRestoresConnection(): void
    {
        // Open connection as user-1, get reconnect token
        $this->controller->onOpen(1, 'user-1');
        $connectedPayload = $this->lastPushedPayload();
        $reconnectToken = $connectedPayload['reconnectToken'];

        // Close the connection
        $this->controller->onClose(1);
        $this->assertNull($this->registry->getConnection(1));

        // Simulate a new connection on the same FD (as would happen after reconnect)
        // The WithWebSocketHandler will call onOpen, but we test auth.reconnect directly
        $this->pushedMessages = [];
        $this->controller->onMessage(1, json_encode([
            'type' => 'auth.reconnect',
            'reconnectToken' => $reconnectToken,
        ]));

        // Connection should be restored
        $conn = $this->registry->getConnection(1);
        $this->assertNotNull($conn);
        $this->assertSame('user-1', $conn['user_id']);

        // Should receive a new connected message with a new token
        $this->assertLastPushMatches(1, 'connected');
        $payload = $this->lastPushedPayload();
        $this->assertArrayHasKey('reconnectToken', $payload);
        $this->assertArrayHasKey('reconnected', $payload);
        $this->assertTrue($payload['reconnected']);
        $this->assertNotSame($reconnectToken, $payload['reconnectToken']);
    }

    public function testAuthReconnectWithInvalidTokenFails(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $this->pushedMessages = [];

        $this->controller->onMessage(1, json_encode([
            'type' => 'auth.reconnect',
            'reconnectToken' => 'invalid-token-here',
        ]));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Invalid or expired reconnection token']);
    }

    public function testAuthReconnectWithMissingTokenFails(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $this->pushedMessages = [];

        $this->controller->onMessage(1, json_encode([
            'type' => 'auth.reconnect',
        ]));

        $this->assertLastPushMatches(1, 'error', ['message' => 'auth.reconnect requires a "reconnectToken" field']);
    }

    public function testAuthReconnectTokenIsSingleUse(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $reconnectToken = $this->lastPushedPayload()['reconnectToken'];

        $this->controller->onClose(1);
        $this->pushedMessages = [];

        // First use succeeds
        $this->controller->onMessage(1, json_encode([
            'type' => 'auth.reconnect',
            'reconnectToken' => $reconnectToken,
        ]));
        $this->assertLastPushMatches(1, 'connected');

        // Try to use the same token again (without closing first)
        $this->controller->onMessage(1, json_encode([
            'type' => 'auth.reconnect',
            'reconnectToken' => $reconnectToken,
        ]));
        $this->assertLastPushMatches(1, 'error', ['message' => 'Invalid or expired reconnection token']);
    }
}
