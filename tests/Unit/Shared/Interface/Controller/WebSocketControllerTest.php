<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Application\Port\ListeningSessionInteractionInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\ResultStampMiddleware;
use App\Shared\Infrastructure\Messenger\Stamp\PartyMemberResultStamp;
use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Interface\Controller\WebSocketController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Swoole\Table;
use Swoole\WebSocket\Server;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebSocketControllerTest extends TestCase
{
    private WebSocketConnectionRegistry $registry;
    private WebSocketPusher $pusher;
    private Server&Stub $server;
    private MessageBusInterface&Stub $bus;
    private ReconnectionTokenService $reconnectionTokens;
    private WebSocketController $controller;
    private ListeningSessionInteractionInterface&Stub $listeningSessions;

    /** @var list<array{fd: int, data: string}> Captured push calls from the mock server. */
    private array $pushedMessages = [];

    /** @var list<array{fd: int, code: int, reason: string}> Captured close frames from the mock server. */
    private array $closedConnections = [];

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
        $this->closedConnections = [];
        $this->server = $this->createStub(Server::class);
        $this->server->worker_id = 0;

        $pushedMessages = &$this->pushedMessages;
        $this->server->method('isEstablished')->willReturn(true);
        $this->server->method('push')->willReturnCallback(
            function (int $fd, string $data) use (&$pushedMessages): bool {
                $pushedMessages[] = ['fd' => $fd, 'data' => $data];

                return true;
            },
        );

        $closedConnections = &$this->closedConnections;
        $this->server->method('disconnect')->willReturnCallback(
            function (int $fd, int $code, string $reason) use (&$closedConnections): bool {
                $closedConnections[] = ['fd' => $fd, 'code' => $code, 'reason' => $reason];

                return true;
            },
        );

        $this->pusher = new WebSocketPusher($this->registry, new JsonEncoder());
        $this->pusher->setServer($this->server);

        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->listeningSessions = $this->createStub(ListeningSessionInteractionInterface::class);

        $this->reconnectionTokens = ReconnectionTokenService::create(maxTokens: 64);

        $this->controller = new WebSocketController(
            $this->registry,
            $this->pusher,
            $this->bus,
            new JsonEncoder(),
            $this->listeningSessions,
            $this->reconnectionTokens,
        );
    }

    /** @param callable(JoinPartySessionCommand): PartyMember $handler */
    private function controllerWithJoinHandler(callable $handler, MiddlewareInterface ...$middleware): WebSocketController
    {
        $bus = new MessageBus([...$middleware, new HandleMessageMiddleware(new HandlersLocator([
            JoinPartySessionCommand::class => [$handler],
        ]))]);

        return new WebSocketController($this->registry, $this->pusher, $bus, new JsonEncoder(), $this->listeningSessions);
    }

    /** @return array<string, mixed>|null */
    private function lastPushedPayload(): ?array
    {
        $last = end($this->pushedMessages);
        if ($last === false) {
            return null;
        }

        return json_decode($last['data'], true);
    }

    /** @return list<array{fd: int, payload: array<string, mixed>}> */
    private function allPushedPayloads(): array
    {
        return array_map(fn (array $m): array => [
            'fd' => $m['fd'],
            'payload' => json_decode($m['data'], true),
        ], $this->pushedMessages);
    }

    /** @param array<string, mixed> $extra */
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

    #[DataProvider('listeningSessionResponses')]
    public function testListeningSessionResponsePreservesUserAndPayload(string $type, string $method, string $responseType): void
    {
        $userId = Uuid::generate();
        $deviceId = Uuid::generate();
        $received = [];
        $this->listeningSessions->method($method)->willReturnCallback(
            function (...$arguments) use (&$received): array {
                $received = $arguments;

                return ['sessionId' => 'session-1', 'active' => false];
            },
        );
        $this->controller->onOpen(1, $userId->toString());
        $this->controller->onMessage(1, json_encode([
            'type' => $type,
            'deviceId' => $deviceId->toString(),
            'userId' => Uuid::generate()->toString(),
            'action' => 'seek',
            'position' => 12.5,
            'queue' => ['track-1'],
            'currentTrackIndex' => 2,
            'playbackState' => 'playing',
        ], JSON_THROW_ON_ERROR));

        self::assertSame($userId->toString(), $received[0]->toString());
        self::assertSame($deviceId->toString(), $received[1]->toString());
        if ($method === 'playback') {
            self::assertSame(['seek', 12.5, ['track-1'], 2, 'playing'], array_slice($received, 2));
        } elseif ($method === 'sync') {
            self::assertSame([['track-1'], 2, 12.5, 'playing'], array_slice($received, 2));
        }
        self::assertSame(
            ['type' => $responseType, 'data' => ['sessionId' => 'session-1', 'active' => false]],
            $this->lastPushedPayload(),
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function listeningSessionResponses(): iterable
    {
        yield 'join' => ['session.join', 'join', 'session.joined'];
        yield 'playback' => ['session.playback', 'playback', 'session.playback_result'];
        yield 'sync' => ['session.sync', 'sync', 'session.sync_result'];
    }

    #[DataProvider('listeningSessionFailures')]
    public function testListeningSessionFailureUsesExistingError(string $type, string $method, string $error): void
    {
        $this->listeningSessions->method($method)->willThrowException(new HandlerFailedException(
            new Envelope(new \stdClass()), [new \RuntimeException('Private handler detail')],
        ));
        $this->controller->onOpen(1, Uuid::generate()->toString());
        $this->controller->onMessage(1, json_encode([
            'type' => $type,
            'deviceId' => Uuid::generate()->toString(),
            'action' => 'pause',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['type' => 'error', 'message' => $error], $this->lastPushedPayload());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function listeningSessionFailures(): iterable
    {
        yield 'join' => ['session.join', 'join', 'Failed to join session'];
        yield 'playback' => ['session.playback', 'playback', 'Playback action failed'];
        yield 'sync' => ['session.sync', 'sync', 'Sync failed'];
    }

    public function testListeningSessionPlaybackDefaultsRemainNullable(): void
    {
        $received = [];
        $this->listeningSessions->method('playback')->willReturnCallback(
            function (...$arguments) use (&$received): array {
                $received = $arguments;

                return [];
            },
        );
        $this->controller->onOpen(1, Uuid::generate()->toString());
        $this->controller->onMessage(1, json_encode([
            'type' => 'session.playback', 'deviceId' => Uuid::generate()->toString(), 'action' => 'pause',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['pause', null, null, null, null], array_slice($received, 2));
        self::assertSame(
            ['type' => 'session.playback_result', 'data' => []],
            $this->lastPushedPayload(),
        );
    }

    public function testListeningSessionSyncDefaultsRemainExplicit(): void
    {
        $received = [];
        $this->listeningSessions->method('sync')->willReturnCallback(
            function (...$arguments) use (&$received): array {
                $received = $arguments;

                return [];
            },
        );
        $this->controller->onOpen(1, Uuid::generate()->toString());
        $this->controller->onMessage(1, json_encode([
            'type' => 'session.sync', 'deviceId' => Uuid::generate()->toString(),
        ], JSON_THROW_ON_ERROR));

        self::assertSame([[], 0, 0.0, 'paused'], array_slice($received, 2));
        self::assertSame(
            ['type' => 'session.sync_result', 'data' => []],
            $this->lastPushedPayload(),
        );
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
            $this->listeningSessions,
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

    public function testPartyJoinReportsTheRoleOfTheJoinedMember(): void
    {
        $userId = '01900000-0000-7000-8000-000000000001';
        $sessionId = '01900000-0000-7000-8000-000000000002';
        $controller = $this->controllerWithJoinHandler(static fn (JoinPartySessionCommand $command): PartyMember => PartyMember::create(
            $command->getUserId(),
            $command->getSessionId(),
            MemberRole::Host,
        ), new ResultStampMiddleware([PartyMemberResultStamp::class]));
        $controller->onOpen(1, $userId);

        $controller->onMessage(1, json_encode(['type' => 'party.join', 'sessionId' => $sessionId], JSON_THROW_ON_ERROR));

        self::assertSame([1], $this->registry->getRoomMembers('party:' . $sessionId));
        self::assertContains(['fd' => 1, 'payload' => ['type' => 'party.joined', 'sessionId' => $sessionId, 'role' => 'host']], $this->allPushedPayloads());
    }

    public function testPartyJoinWithoutAStampedResultSendsErrorAndKeepsConnectionUsable(): void
    {
        $sessionId = '01900000-0000-7000-8000-000000000002';
        // Without ResultStampMiddleware the joined member never reaches the controller.
        $controller = $this->controllerWithJoinHandler(static fn (JoinPartySessionCommand $command): PartyMember => PartyMember::create(
            $command->getUserId(),
            $command->getSessionId(),
        ));
        $controller->onOpen(1, '01900000-0000-7000-8000-000000000001');

        $controller->onMessage(1, json_encode(['type' => 'party.join', 'sessionId' => $sessionId], JSON_THROW_ON_ERROR));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Failed to join party session']);
        self::assertSame([], $this->registry->getRoomMembers('party:' . $sessionId));
        $controller->onMessage(1, '{"type":"ping"}');
        $this->assertLastPushMatches(1, 'pong');
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function failedPartyCommands(): iterable
    {
        yield 'join' => ['party.join', null];
        yield 'leave' => ['party.leave', null];
        yield 'play' => ['party.playback', 'play'];
        yield 'pause' => ['party.playback', 'pause'];
        yield 'seek' => ['party.playback', 'seek'];
    }

    #[DataProvider('failedPartyCommands')]
    public function testPartyHandlerFailureSendsErrorAndKeepsConnectionUsable(string $type, ?string $action): void
    {
        $userId = '01900000-0000-7000-8000-000000000001';
        $sessionId = '01900000-0000-7000-8000-000000000002';
        $this->bus->method('dispatch')->willReturnCallback(
            static function (object $command): never {
                throw new HandlerFailedException(new Envelope($command), [
                    'party.handler' => new \RuntimeException('Party action denied'),
                ]);
            },
        );
        $this->controller->onOpen(1, $userId);
        $messageCount = count($this->pushedMessages);

        $this->controller->onMessage(1, json_encode([
            'type' => $type,
            'sessionId' => $sessionId,
            'action' => $action,
            'position' => 12.5,
        ], JSON_THROW_ON_ERROR));

        $this->assertCount($messageCount + 1, $this->pushedMessages);
        $this->assertLastPushMatches(1, 'error', ['message' => 'Party action denied']);
        $this->controller->onMessage(1, '{"type":"ping"}');
        $this->assertLastPushMatches(1, 'pong');
    }

    public function testPartySyncUsesAuthenticatedUserAndReturnsTheHandledPosition(): void
    {
        $userId = '01900000-0000-7000-8000-000000000001';
        $sessionId = '01900000-0000-7000-8000-000000000002';
        $received = null;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SyncPlaybackCommand::class => [static function (SyncPlaybackCommand $command) use (&$received): float {
                $received = $command;

                return 43.25;
            }],
        ]))]);
        $controller = new WebSocketController($this->registry, $this->pusher, $bus, new JsonEncoder(), $this->listeningSessions);
        $controller->onOpen(1, $userId);

        $controller->onMessage(1, json_encode([
            'type' => 'party.sync',
            'sessionId' => $sessionId,
            'userId' => '01900000-0000-7000-8000-000000000003',
            'position' => 42,
            'latency' => 0.25,
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(SyncPlaybackCommand::class, $received);
        self::assertSame($userId, $received->getUserId()->toString());
        self::assertSame($sessionId, $received->getSessionId()->toString());
        self::assertSame(42.0, $received->getClientPosition());
        self::assertSame(0.25, $received->getClientLatency());
        $this->assertLastPushMatches(1, 'party.sync_response', ['serverPosition' => 43.25]);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidPartySyncNumbers(): iterable
    {
        yield 'negative position' => [-1, 0];
        yield 'text position' => ['42', 0];
        yield 'array position' => [[], 0];
        yield 'boolean position' => [true, 0];
        yield 'null position' => [null, 0];
        yield 'null latency' => [0, null];
        yield 'negative latency' => [0, -1];
        yield 'excessive latency' => [0, 10.01];
        yield 'text latency' => [0, 'fast'];
    }

    #[DataProvider('invalidPartySyncNumbers')]
    public function testPartySyncRejectsInvalidNumbersBeforeDispatch(mixed $position, mixed $latency): void
    {
        $this->bus->method('dispatch')->willReturnCallback(static function (): never {
            self::fail('Invalid sync input must not reach the message bus.');
        });
        $this->controller->onOpen(1, '01900000-0000-7000-8000-000000000001');

        $this->controller->onMessage(1, json_encode([
            'type' => 'party.sync',
            'sessionId' => '01900000-0000-7000-8000-000000000002',
            'position' => $position,
            'latency' => $latency,
        ], JSON_THROW_ON_ERROR));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Invalid position or latency']);
    }

    public function testPartySyncWithoutHandledResultFailsClosed(): void
    {
        $this->bus->method('dispatch')->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));
        $this->controller->onOpen(1, '01900000-0000-7000-8000-000000000001');

        $this->controller->onMessage(1, json_encode([
            'type' => 'party.sync',
            'sessionId' => '01900000-0000-7000-8000-000000000002',
        ], JSON_THROW_ON_ERROR));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Sync failed']);
    }

    public function testPartySyncRejectsOverflowingJsonNumber(): void
    {
        $this->controller->onOpen(1, '01900000-0000-7000-8000-000000000001');

        $this->controller->onMessage(1, '{"type":"party.sync","sessionId":"01900000-0000-7000-8000-000000000002","position":1e999}');

        $this->assertLastPushMatches(1, 'error', ['message' => 'Invalid position or latency']);
    }

    public function testPartySyncHandlerDenialDoesNotExposeSessionData(): void
    {
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SyncPlaybackCommand::class => [static function (): never {
                throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Private party');
            }],
        ]))]);
        $controller = new WebSocketController($this->registry, $this->pusher, $bus, new JsonEncoder(), $this->listeningSessions);
        $controller->onOpen(1, '01900000-0000-7000-8000-000000000001');
        $messageCount = count($this->pushedMessages);

        $controller->onMessage(1, '{"type":"party.sync","sessionId":"01900000-0000-7000-8000-000000000002"}');

        self::assertCount($messageCount + 1, $this->pushedMessages);
        self::assertSame(['type' => 'error', 'message' => 'Sync failed'], $this->lastPushedPayload());
        $controller->onMessage(1, '{"type":"ping"}');
        $this->assertLastPushMatches(1, 'pong');
    }

    // --- Rate limiting ---

    public function testRateLimitTriggersAfterMaxMessagesPerSecond(): void
    {
        $this->controller->onOpen(1, 'user-1');

        for ($i = 0; $i < 30; ++$i) {
            $this->controller->onMessage(1, json_encode(['type' => 'ping']));
        }

        $all = $this->allPushedPayloads();
        $pongCount = array_filter($all, fn (array $m): bool => $m['payload']['type'] === 'pong');
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

        $this->assertConnectionMissing(1);
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

        $this->assertConnectionMissing(1);
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
        $this->assertConnectionMissing(1);

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

    // --- Registrations the registry refuses ---

    public function testOnOpenOverTheUserLimitClosesTheConnectionWithoutRegisteringIt(): void
    {
        for ($fd = 1; $fd <= 10; ++$fd) {
            $this->controller->onOpen($fd, 'user-1');
        }
        $this->pushedMessages = [];

        $this->controller->onOpen(11, 'user-1');

        self::assertSame([['fd' => 11, 'code' => 1008, 'reason' => 'Too many connections for this user']], $this->closedConnections);
        self::assertSame([], $this->pushedMessages, 'A refused connection was told it is connected.');
        $this->assertConnectionMissing(11);
        $this->controller->onMessage(11, '{"type":"ping"}');
        $this->assertLastPushMatches(11, 'error', ['message' => 'Not authenticated']);
    }

    public function testOnOpenWhenTheConnectionTableIsFullClosesTheConnectionWithoutRegisteringIt(): void
    {
        self::fillTable($this->registryTable('connections'), ['user_id' => 'filler', 'worker_id' => 0, 'connected_at' => 0]);

        $this->controller->onOpen(1, 'user-1');

        self::assertSame([['fd' => 1, 'code' => 1013, 'reason' => 'Server connection limit reached']], $this->closedConnections);
        self::assertSame([], $this->pushedMessages);
        $this->assertConnectionMissing(1);
    }

    public function testOnOpenWithoutAStoredReconnectTokenStillConnects(): void
    {
        $tokens = (new \ReflectionProperty($this->reconnectionTokens, 'tokens'))->getValue($this->reconnectionTokens);
        self::assertInstanceOf(Table::class, $tokens);
        self::fillTable($tokens, ['user_id' => 'filler', 'created_at' => time()]);

        $this->controller->onOpen(1, 'user-1');

        self::assertSame([['fd' => 1, 'payload' => ['type' => 'connected']]], $this->allPushedPayloads());
        self::assertNotNull($this->registry->getConnection(1));
    }

    public function testRoomJoinTheRegistryRefusesSendsAnErrorAndRecordsNoMembership(): void
    {
        $this->controller->onOpen(1, 'user-1');
        self::fillTable($this->registryTable('roomMembers'), ['joined_at' => 0]);

        $this->controller->onMessage(1, json_encode(['type' => 'room.join', 'room' => 'room:full'], JSON_THROW_ON_ERROR));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Room limit reached; try again later']);
        self::assertSame([], $this->registry->getRoomMembers('room:full'));
        self::assertSame([], $this->closedConnections);
    }

    public function testRoomJoinWithANameTooLongForTheRegistrySendsAnError(): void
    {
        $this->controller->onOpen(1, 'user-1');
        $room = str_repeat('r', WebSocketConnectionRegistry::MAX_ROOM_NAME_BYTES + 1);

        $this->controller->onMessage(1, json_encode(['type' => 'room.join', 'room' => $room], JSON_THROW_ON_ERROR));

        $this->assertLastPushMatches(1, 'error', ['message' => 'room.join "room" may be at most 52 bytes long']);
        self::assertSame([], $this->registry->getRoomMembers($room));
    }

    public function testPartyJoinTheRegistryRefusesSendsAnErrorInsteadOfJoined(): void
    {
        $sessionId = '01900000-0000-7000-8000-000000000002';
        $controller = $this->controllerWithJoinHandler(static fn (JoinPartySessionCommand $command): PartyMember => PartyMember::create(
            $command->getUserId(),
            $command->getSessionId(),
        ), new ResultStampMiddleware([PartyMemberResultStamp::class]));
        $controller->onOpen(1, '01900000-0000-7000-8000-000000000001');
        self::fillTable($this->registryTable('roomMembers'), ['joined_at' => 0]);

        $controller->onMessage(1, json_encode(['type' => 'party.join', 'sessionId' => $sessionId], JSON_THROW_ON_ERROR));

        $this->assertLastPushMatches(1, 'error', ['message' => 'Room limit reached; try again later']);
        self::assertSame([], $this->registry->getRoomMembers('party:' . $sessionId));
        foreach ($this->allPushedPayloads() as $push) {
            self::assertNotSame('party.joined', $push['payload']['type']);
        }
    }

    public function testAuthReconnectOverTheUserLimitClosesTheConnection(): void
    {
        $this->controller->onOpen(1, 'user-1');
        for ($fd = 2; $fd <= 11; ++$fd) {
            $this->registry->addConnection($fd, 'user-2', 0);
        }
        $user2Token = $this->reconnectionTokens->generate('user-2');
        self::assertNotNull($user2Token);

        $this->controller->onMessage(1, json_encode(['type' => 'auth.reconnect', 'reconnectToken' => $user2Token], JSON_THROW_ON_ERROR));

        self::assertSame([['fd' => 1, 'code' => 1008, 'reason' => 'Too many connections for this user']], $this->closedConnections);
        $this->assertConnectionMissing(1);
        $this->controller->onMessage(1, '{"type":"ping"}');
        $this->assertLastPushMatches(1, 'error', ['message' => 'Not authenticated']);
    }

    private function registryTable(string $property): Table
    {
        $table = (new \ReflectionProperty($this->registry, $property))->getValue($this->registry);
        self::assertInstanceOf(Table::class, $table);

        return $table;
    }

    /**
     * Writes filler rows until a thousand writes in a row are refused, so no key
     * can still find a free bucket.
     *
     * @param array<string, int|string> $row
     */
    private static function fillTable(Table $table, array $row): void
    {
        $refusedInARow = 0;
        for ($key = 0; $refusedInARow < 1000; ++$key) {
            self::assertLessThan(100_000, $key, 'The table never filled up.');
            $refusedInARow = @$table->set('filler-' . $key, $row) ? 0 : $refusedInARow + 1;
        }
    }

    private function assertConnectionMissing(int $fd): void
    {
        self::assertNull($this->registry->getConnection($fd));
    }

}
