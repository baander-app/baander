<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Party\Application\Command\JoinPartySessionCommand;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Session\Application\Command\SessionJoinCommand;
use App\Session\Application\Command\SessionPlaybackCommand;
use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\Command\PausePlaybackCommand;
use App\Party\Application\Command\SeekPlaybackCommand;
use App\Party\Application\Command\StartPlaybackCommand;
use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Domain\ValueObject\PlaybackAction;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Messenger\Stamp\PartyMemberResultStamp;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\AbstractWebSocketController;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Throwable;
use function count;
use function is_array;
use function is_string;

final class WebSocketController extends AbstractWebSocketController
{
    private const int MAX_MESSAGES_PER_SECOND = 30;

    /** @var array<int, string> FD => userId (populated on open, cleared on close) */
    private array $fdUsers = [];

    /** @var array<int, list<float>> FD => list of microsecond timestamps */
    private array $rateLimitWindows = [];

    public function __construct(
        private readonly WebSocketConnectionRegistry $registry,
        private readonly WebSocketPusher $pusher,
        private readonly MessageBusInterface $bus,
        private readonly JsonEncoder $jsonEncoder,
        private readonly ?ReconnectionTokenService $reconnectionTokens = null,
        private readonly ?LoggerInterface $logger = null,
    )
    {
    }

    public function onOpen(int $fd, string $userId): void
    {
        $this->registry->addConnection($fd, $userId, $this->registry->getWorkerId());
        $this->fdUsers[$fd] = $userId;

        $this->logger?->debug('WebSocket connected', ['fd' => $fd, 'userId' => $userId, 'workerId' => $this->registry->getWorkerId()]);

        $message = ['type' => 'connected'];

        if ($this->reconnectionTokens !== null) {
            $message['reconnectToken'] = $this->reconnectionTokens->generate($userId);
        }

        $this->pusher->pushToConnection($fd, $message);
    }

    public function onMessage(int $fd, string $data): void
    {
        $payload = $this->deserialize($fd, $data);
        if ($payload !== null && ($payload['type'] ?? '') === 'auth.reconnect') {
            $this->handleAuthReconnect($fd, $payload);

            return;
        }

        if (!$this->checkRateLimit($fd)) {
            $this->logger?->warning('WebSocket rate limited', ['fd' => $fd]);

            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Rate limit exceeded',
            ]);

            return;
        }

        if ($payload === null) {
            return;
        }

        $userId = $this->fdUsers[$fd] ?? '';
        if ($userId === '') {
            $this->logger?->warning('WebSocket message from unauthenticated connection', ['fd' => $fd]);

            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Not authenticated',
            ]);

            return;
        }

        $type = $payload['type'];
        $this->logger?->debug('WebSocket message', ['fd' => $fd, 'userId' => $userId, 'type' => $type]);

        match ($type) {
            'ping' => $this->pusher->pushToConnection($fd, ['type' => 'pong']),
            'room.join' => $this->handleRoomJoin($fd, $userId, $payload),
            'room.leave' => $this->handleRoomLeave($fd, $payload),
            'party.join' => $this->handlePartyJoin($fd, $userId, $payload),
            'party.leave' => $this->handlePartyLeave($fd, $userId, $payload),
            'party.playback' => $this->handlePartyPlayback($fd, $userId, $payload),
            'party.sync' => $this->handlePartySync($fd, $payload),
            'session.join' => $this->handleSessionJoin($fd, $userId, $payload),
            'session.playback' => $this->handleSessionPlayback($fd, $userId, $payload),
            'session.sync' => $this->handleSessionSync($fd, $userId, $payload),
            'transcode.position' => $this->handleTranscodePosition($fd, $payload),
            default => $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => sprintf('Unknown message type: "%s"', $type),
            ]),
        };
    }

    private function deserialize(int $fd, string $data): ?array
    {
        try {
            $payload = $this->jsonEncoder->decode($data, 'json');
        } catch (NotEncodableValueException) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid JSON',
            ]);

            return null;
        }

        if (!is_array($payload) || !isset($payload['type']) || !is_string($payload['type']) || $payload['type'] === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid message format: must be JSON with a "type" field',
            ]);

            return null;
        }

        return $payload;
    }

    private function handleAuthReconnect(int $fd, array $payload): void
    {
        if ($this->reconnectionTokens === null) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Reconnection not supported',
            ]);

            return;
        }

        $token = $payload['reconnectToken'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'auth.reconnect requires a "reconnectToken" field',
            ]);

            return;
        }

        $userId = $this->reconnectionTokens->consume($token);
        if ($userId === null) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid or expired reconnection token',
            ]);

            return;
        }

        // Re-register the connection with the restored user identity
        $this->registry->removeConnection($fd);
        $this->registry->addConnection($fd, $userId, $this->registry->getWorkerId());
        $this->fdUsers[$fd] = $userId;

        $this->logger?->info('WebSocket reconnected', ['fd' => $fd, 'userId' => $userId]);

        // Generate a new reconnection token
        $newToken = $this->reconnectionTokens->generate($userId);

        $this->pusher->pushToConnection($fd, [
            'type'           => 'connected',
            'reconnectToken' => $newToken,
            'reconnected'    => true,
        ]);
    }

    private function checkRateLimit(int $fd): bool
    {
        $now = microtime(true);
        $window = $this->rateLimitWindows[$fd] ?? [];
        $window = array_filter($window, fn(float $t): bool => $now - $t < 1.0);
        $window[] = $now;
        $this->rateLimitWindows[$fd] = array_values($window);

        return count($window) <= self::MAX_MESSAGES_PER_SECOND;
    }

    private function handleRoomJoin(int $fd, string $userId, array $payload): void
    {
        $room = $payload['room'] ?? null;
        if (!is_string($room) || $room === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'room.join requires a non-empty "room" field',
            ]);

            return;
        }

        $this->registry->joinRoom($room, $fd);
        $this->logger?->debug('Room joined', ['fd' => $fd, 'userId' => $userId, 'room' => $room]);

        $this->pusher->pushToConnection($fd, [
            'type' => 'room.joined',
            'room' => $room,
        ]);
    }

    private function handleRoomLeave(int $fd, array $payload): void
    {
        $room = $payload['room'] ?? null;
        if (!is_string($room) || $room === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'room.leave requires a non-empty "room" field',
            ]);

            return;
        }

        $this->registry->leaveRoom($room, $fd);
        $this->logger?->debug('Room left', ['fd' => $fd, 'room' => $room]);

        $this->pusher->pushToConnection($fd, [
            'type' => 'room.left',
            'room' => $room,
        ]);
    }

    private function handlePartyJoin(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'party.join requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $sessionUuid = Uuid::fromString($sessionId);
            $userUuid = Uuid::fromString($userId);
        } catch (InvalidArgumentException $e) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid UUID format',
            ]);

            return;
        }

        try {
            $envelope = $this->bus->dispatch(
                new JoinPartySessionCommand($userUuid, $sessionUuid),
            );
            $member = $envelope->last(PartyMemberResultStamp::class)?->getMember();
        } catch (HandlerFailedException $e) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => $e->getNestedExceptions()[0]?->getMessage() ?? $e->getMessage(),
            ]);

            return;
        } catch (Throwable $e) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Failed to join party session',
            ]);

            return;
        }

        // Add to Swoole Table room for broadcasting
        $room = sprintf('party:%s', $sessionId);
        $this->registry->joinRoom($room, $fd);
        $this->logger?->info('Party joined', ['fd' => $fd, 'userId' => $userId, 'sessionId' => $sessionId, 'role' => $member->getRole()->value]);

        $this->pusher->pushToConnection($fd, [
            'type'      => 'party.joined',
            'sessionId' => $sessionId,
            'role'      => $member->getRole()->value,
        ]);

        // Broadcast member event to room
        $this->pusher->broadcast($room, [
            'type'      => 'party.member_event',
            'sessionId' => $sessionId,
            'action'    => 'join',
            'userId'    => $userId,
            'role'      => $member->getRole()->value,
        ]);
    }

    private function handlePartyLeave(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'party.leave requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $sessionUuid = Uuid::fromString($sessionId);
            $userUuid = Uuid::fromString($userId);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid UUID format',
            ]);

            return;
        }

        try {
            $this->bus->dispatch(
                new LeavePartySessionCommand($userUuid, $sessionUuid),
            );
        } catch (HandlerFailedException $e) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => $e->getNestedExceptions()[0]?->getMessage() ?? $e->getMessage(),
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Failed to leave party session',
            ]);

            return;
        }

        // Remove from Swoole Table room
        $room = sprintf('party:%s', $sessionId);
        $this->registry->leaveRoom($room, $fd);
        $this->logger?->info('Party left', ['fd' => $fd, 'userId' => $userId, 'sessionId' => $sessionId]);

        // Broadcast member event to room
        $this->pusher->broadcast($room, [
            'type'      => 'party.member_event',
            'sessionId' => $sessionId,
            'action'    => 'leave',
            'userId'    => $userId,
        ]);
    }

    private function handlePartyPlayback(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        $action = $payload['action'] ?? null;

        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'party.playback requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $sessionUuid = Uuid::fromString($sessionId);
            $playbackAction = PlaybackAction::from($action ?? '');
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid sessionId or action',
            ]);

            return;
        }

        try {
            switch ($playbackAction) {
                case PlaybackAction::Play:
                    $this->logger?->debug('Playback play', ['fd' => $fd, 'sessionId' => $sessionId, 'userId' => $userId]);
                    $this->bus->dispatch(
                        new StartPlaybackCommand(
                            $sessionUuid,
                            Uuid::fromString($userId),
                            $payload['position'] ?? null,
                        ),
                    );
                    break;

                case PlaybackAction::Pause:
                    $this->bus->dispatch(
                        new PausePlaybackCommand($sessionUuid, Uuid::fromString($userId)),
                    );
                    break;

                case PlaybackAction::Seek:
                    $this->bus->dispatch(
                        new SeekPlaybackCommand(
                            $sessionUuid,
                            Uuid::fromString($userId),
                            $payload['position'] ?? 0.0,
                        ),
                    );
                    break;

                default:
                    return;
            }
        } catch (HandlerFailedException $e) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => $e->getNestedExceptions()[0]?->getMessage() ?? $e->getMessage(),
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Playback action failed',
            ]);

            return;
        }

        // Fetch updated session state for broadcast
        $sessionPort = null; // Would need injection — skip for now, rely on client polling
        // The playback state broadcast will be handled by the existing event system
        // when the command handler dispatches domain events.
    }

    private function handlePartySync(int $fd, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        $position = (float)($payload['position'] ?? 0.0);
        $latency = (float)($payload['latency'] ?? 0.0);

        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'party.sync requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $sessionUuid = Uuid::fromString($sessionId);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid sessionId format',
            ]);

            return;
        }

        try {
            $serverPosition = $this->bus->dispatch(
                new SyncPlaybackCommand(
                    $sessionUuid,
                    $position,
                    $latency,
                ),
            );
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Sync failed',
            ]);

            return;
        }

        $this->pusher->pushToConnection($fd, [
            'type'           => 'party.sync_response',
            'sessionId'      => $sessionId,
            'serverPosition' => $serverPosition,
        ]);
    }

    private function handleSessionJoin(int $fd, string $userId, array $payload): void
    {
        $deviceId = $payload['deviceId'] ?? null;
        if (!is_string($deviceId) || $deviceId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'session.join requires a "deviceId" field',
            ]);

            return;
        }

        try {
            $userUuid = Uuid::fromString($userId);
            $deviceUuid = Uuid::fromString($deviceId);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Invalid UUID format']);

            return;
        }

        try {
            $envelope = $this->bus->dispatch(
                new SessionJoinCommand($userUuid, $deviceUuid),
            );
            $handledStamp = $envelope->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class);
            $result = $handledStamp?->getResult() ?? [];
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Failed to join session']);

            return;
        }

        $this->pusher->pushToConnection($fd, [
            'type' => 'session.joined',
            'data' => $result,
        ]);
    }

    private function handleSessionPlayback(int $fd, string $userId, array $payload): void
    {
        $deviceId = $payload['deviceId'] ?? null;
        $action = $payload['action'] ?? null;

        if (!is_string($deviceId) || $deviceId === '' || !is_string($action) || $action === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'session.playback requires "deviceId" and "action" fields',
            ]);

            return;
        }

        try {
            $userUuid = Uuid::fromString($userId);
            $deviceUuid = Uuid::fromString($deviceId);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Invalid UUID format']);

            return;
        }

        try {
            $envelope = $this->bus->dispatch(new SessionPlaybackCommand(
                userId: $userUuid,
                deviceId: $deviceUuid,
                action: $action,
                position: isset($payload['position']) ? (float) $payload['position'] : null,
                queue: $payload['queue'] ?? null,
                currentTrackIndex: isset($payload['currentTrackIndex']) ? (int) $payload['currentTrackIndex'] : null,
                playbackState: $payload['playbackState'] ?? null,
            ));
            $handledStamp = $envelope->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class);
            $result = $handledStamp?->getResult() ?? [];
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Playback action failed']);

            return;
        }

        $this->pusher->pushToConnection($fd, [
            'type' => 'session.playback_result',
            'data' => $result,
        ]);
    }

    private function handleSessionSync(int $fd, string $userId, array $payload): void
    {
        $deviceId = $payload['deviceId'] ?? null;

        if (!is_string($deviceId) || $deviceId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'session.sync requires a "deviceId" field',
            ]);

            return;
        }

        try {
            $userUuid = Uuid::fromString($userId);
            $deviceUuid = Uuid::fromString($deviceId);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Invalid UUID format']);

            return;
        }

        try {
            // Reuse existing SyncSessionCommand (same fields, same handler)
            $envelope = $this->bus->dispatch(new \App\Session\Application\Command\SyncSessionCommand(
                userId: $userUuid,
                deviceId: $deviceUuid,
                queue: $payload['queue'] ?? [],
                currentTrackIndex: (int) ($payload['currentTrackIndex'] ?? 0),
                position: (float) ($payload['position'] ?? 0.0),
                playbackState: $payload['playbackState'] ?? 'paused',
            ));
            $handledStamp = $envelope->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class);
            $result = $handledStamp?->getResult() ?? [];
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Sync failed']);

            return;
        }

        $this->pusher->pushToConnection($fd, [
            'type' => 'session.sync_result',
            'data' => $result,
        ]);
    }

    public function onClose(int $fd): void
    {
        $userId = $this->fdUsers[$fd] ?? null;
        $this->registry->removeConnection($fd);
        unset($this->fdUsers[$fd], $this->rateLimitWindows[$fd]);

        $this->logger?->debug('WebSocket closed', ['fd' => $fd, 'userId' => $userId]);
    }

    private function handleTranscodePosition(int $fd, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        $position = $payload['position'] ?? null;
        $action = $payload['action'] ?? 'seek';

        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'transcode.position requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $sessionUuid = Uuid::fromString($sessionId);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Invalid sessionId format',
            ]);

            return;
        }

        try {
            $this->bus->dispatch(new UpdateTranscodePositionCommand(
                sessionId: $sessionUuid,
                position: (float) ($position ?? 0.0),
                action: (string) $action,
            ));
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type'    => 'error',
                'message' => 'Failed to update transcode position',
            ]);

            return;
        }

        $this->pusher->pushToConnection($fd, [
            'type' => 'transcode.position_ack',
            'sessionId' => $sessionId,
            'position' => (float) ($position ?? 0.0),
        ]);
    }
}
