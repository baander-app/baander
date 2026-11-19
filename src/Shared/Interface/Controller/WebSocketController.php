<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\LiveUpdates\LiveSubscription;
use App\Shared\Application\LiveUpdates\LiveSubscriptions;
use App\Shared\Application\Port\WebSocketIdentityLookupInterface;
use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\Command\PausePlaybackCommand;
use App\Party\Application\Command\SeekPlaybackCommand;
use App\Party\Application\Command\StartPlaybackCommand;
use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Domain\ValueObject\PlaybackAction;
use App\Shared\Application\Port\ListeningSessionInteractionInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\Stamp\PartyMemberResultStamp;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Infrastructure\Swoole\WebSocketRegistrationRefused;
use App\Shared\Interface\WebSocket\WebSocketRoomPolicy;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
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

    /** Frames past the rate limit are refused; at this many in one second the socket is closed. */
    private const int CLOSE_AT_MESSAGES_PER_SECOND = 300;

    /**
     * Largest frame a client may send. Swoole's package_max_length also bounds HTTP
     * request bodies, so it stays large and the controller enforces this cap.
     */
    private const int MAX_FRAME_BYTES = 65536;

    private const int CLOSE_POLICY_VIOLATION = 1008;
    private const int CLOSE_MESSAGE_TOO_BIG = 1009;

    /** @var array<int, string> FD => userId (populated on open, cleared on close) */
    private array $fdUsers = [];

    /** @var array<int, int> */
    private array $connectionGenerations = [];
    private int $nextConnectionGeneration = 0;

    /** @var array<int, list<float>> FD => list of microsecond timestamps */
    private array $rateLimitWindows = [];

    /** @var array<int, true> FDs this controller closed; their remaining frames are dropped. */
    private array $closingFds = [];

    public function __construct(
        private readonly WebSocketConnectionRegistry $registry,
        private readonly WebSocketPusher $pusher,
        private readonly MessageBusInterface $bus,
        private readonly JsonEncoder $jsonEncoder,
        private readonly ListeningSessionInteractionInterface $listeningSessions,
        private readonly WebSocketIdentityLookupInterface $identities,
        private readonly LiveSubscriptions $liveSubscriptions,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function onOpen(int $fd, string $userId, string $accessTokenId): void
    {
        if (!$this->register($fd, $userId, $accessTokenId)) {
            return;
        }

        $this->logger?->debug('WebSocket connected', ['fd' => $fd, 'userId' => $userId, 'workerId' => $this->registry->getWorkerId()]);

        $this->pusher->pushToConnection($fd, ['type' => 'connected']);
    }

    /**
     * Registers the connection, or closes it when the registry refuses it. A refused
     * connection must not stay open: it would get no pushes or broadcasts.
     */
    private function register(int $fd, string $userId, string $accessTokenId): bool
    {
        try {
            $this->registry->addConnection($fd, $userId, $this->registry->getWorkerId(), $accessTokenId);
        } catch (WebSocketRegistrationRefused $refused) {
            unset($this->fdUsers[$fd], $this->connectionGenerations[$fd], $this->playbackEndpoints[$fd]);
            $this->logger?->warning('WebSocket connection refused', [
                'fd' => $fd, 'userId' => $userId, 'reason' => $refused->getMessage(),
            ]);
            $this->pusher->close($fd, $refused->closeCode, $refused->closeReason);

            return false;
        }
        $this->fdUsers[$fd] = $userId;
        $this->connectionGenerations[$fd] = ++$this->nextConnectionGeneration;
        unset($this->playbackEndpoints[$fd]);

        return true;
    }

    /**
     * Adds the connection to a broadcast room. Sends the error and returns false when
     * the registry refuses the membership.
     */
    private function joinBroadcastRoom(int $fd, string $userId, string $room): bool
    {
        try {
            $this->registry->joinRoom($room, $fd);
        } catch (WebSocketRegistrationRefused $refused) {
            $this->logger?->warning('WebSocket room join refused', [
                'fd' => $fd, 'userId' => $userId, 'room' => $room, 'reason' => $refused->getMessage(),
            ]);
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Room limit reached; try again later',
            ]);

            return false;
        }

        return true;
    }

    public function onMessage(int $fd, string $data): void
    {
        if (isset($this->closingFds[$fd])) {
            return;
        }

        if (strlen($data) > self::MAX_FRAME_BYTES) {
            $this->logger?->warning('WebSocket frame too big', ['fd' => $fd, 'bytes' => strlen($data)]);
            $this->closeAbusiveConnection($fd, self::CLOSE_MESSAGE_TOO_BIG, 'Message too big');

            return;
        }

        $framesInLastSecond = $this->recordFrame($fd);
        if ($framesInLastSecond >= self::CLOSE_AT_MESSAGES_PER_SECOND) {
            $this->logger?->warning('WebSocket closed for sustained rate limit excess', ['fd' => $fd]);
            $this->closeAbusiveConnection($fd, self::CLOSE_POLICY_VIOLATION, 'Message rate limit exceeded');

            return;
        }

        if ($framesInLastSecond > self::MAX_MESSAGES_PER_SECOND) {
            $this->logger?->warning('WebSocket rate limited', ['fd' => $fd]);

            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Rate limit exceeded',
            ]);

            return;
        }

        $payload = $this->deserialize($fd, $data);
        if ($payload === null) {
            return;
        }

        $userId = $this->fdUsers[$fd] ?? '';
        if ($userId === '') {
            $this->logger?->warning('WebSocket message from unauthenticated connection', ['fd' => $fd]);

            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Not authenticated',
            ]);

            return;
        }

        $type = $payload['type'];
        $this->logger?->debug('WebSocket message', ['fd' => $fd, 'userId' => $userId, 'type' => $type]);

        match ($type) {
            'live.subscribe' => $this->handleLiveSubscribe($fd, $userId, $payload),
            'ping' => $this->pusher->pushToConnection($fd, ['type' => 'pong']),
            'room.join' => $this->handleRoomJoin($fd, $userId, $payload),
            'room.leave' => $this->handleRoomLeave($fd, $payload),
            'party.join' => $this->handlePartyJoin($fd, $userId, $payload),
            'party.leave' => $this->handlePartyLeave($fd, $userId, $payload),
            'party.playback' => $this->handlePartyPlayback($fd, $userId, $payload),
            'party.sync' => $this->handlePartySync($fd, $userId, $payload),
            'session.endpoint' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'endpoint'),
            'session.transfer' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'transfer'),
            'session.ack' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'ack'),
            'session.control' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'control'),
            'session.observe' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'observe'),
            'session.reconcile' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'reconcile'),
            'session.snapshot' => $this->handlePlaybackCoordination($fd, $userId, $payload, 'snapshot'),
            'transcode.position' => $this->handleTranscodePosition($fd, $userId, $payload),
            default => $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => sprintf('Unknown message type: "%s"', $type),
            ]),
        };
    }

    /** @return array<array-key, mixed>|null */
    private function deserialize(int $fd, string $data): ?array
    {
        try {
            $payload = $this->jsonEncoder->decode($data, 'json');
        } catch (NotEncodableValueException) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid JSON',
            ]);

            return null;
        }

        if (!is_array($payload) || !isset($payload['type']) || !is_string($payload['type']) || $payload['type'] === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid message format: must be JSON with a "type" field',
            ]);

            return null;
        }

        return $payload;
    }

    /** @param array<array-key, mixed> $payload */
    private function handleLiveSubscribe(int $fd, string $userId, array $payload): void
    {
        try {
            LiveSubscription::fromArray($payload);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, ['type' => 'error', 'message' => 'Invalid live subscription']);
            return;
        }
        $this->liveSubscriptions->subscribe($fd, Uuid::fromString($userId));
    }

    /** Records a frame and answers how many frames the connection sent in the last second. */
    private function recordFrame(int $fd): int
    {
        $now = microtime(true);
        $window = $this->rateLimitWindows[$fd] ?? [];
        $window = array_filter($window, fn(float $t): bool => $now - $t < 1.0);
        $window[] = $now;
        $this->rateLimitWindows[$fd] = array_values($window);

        return count($window);
    }

    private function closeAbusiveConnection(int $fd, int $code, string $reason): void
    {
        $this->closingFds[$fd] = true;
        unset($this->rateLimitWindows[$fd]);
        $this->pusher->close($fd, $code, $reason);
    }

    /** @param array<array-key, mixed> $payload */
    private function handleRoomJoin(int $fd, string $userId, array $payload): void
    {
        $room = $payload['room'] ?? null;
        if (!is_string($room) || $room === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'room.join requires a non-empty "room" field',
            ]);

            return;
        }

        // A room's members receive its broadcasts, so no room is joined by name:
        // WebSocketRoomPolicy lists the rooms and the message that joins each.
        $refusal = WebSocketRoomPolicy::clientJoinRefusal($room);
        $this->logger?->warning('WebSocket room join refused', [
            'fd' => $fd, 'userId' => $userId, 'room' => $room, 'reason' => $refusal,
        ]);
        $this->pusher->pushToConnection($fd, [
            'type' => 'error',
            'message' => $refusal,
        ]);
    }

    /** @param array<array-key, mixed> $payload */
    private function handleRoomLeave(int $fd, array $payload): void
    {
        $room = $payload['room'] ?? null;
        if (!is_string($room) || $room === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
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

    private function partyId(string $publicId): Uuid
    {
        $session = $this->identities->partyId(PublicId::fromString($publicId));
        if ($session === null) {
            throw new InvalidArgumentException('Party session not found.');
        }

        return $session;
    }

    /**
     * The party with the public ID when the user holds a member row in it. A party the user is
     * not a member of is refused exactly as an unknown one, so the message that names it gets
     * the answer an unknown party gets.
     */
    private function partyIdForMember(string $publicId, Uuid $userId): Uuid
    {
        return $this->identities->partyIdForMember(PublicId::fromString($publicId), $userId)
            ?? throw new InvalidArgumentException('Party session not found.');
    }

    /**
     * The action of a `party.playback` message when it is one that steers playback. Joining and
     * leaving have messages of their own, so playback acts on neither.
     *
     * @return PlaybackAction::Play|PlaybackAction::Pause|PlaybackAction::Seek|null
     */
    private function steeringAction(mixed $action): ?PlaybackAction
    {
        $action = is_string($action) ? PlaybackAction::tryFrom($action) : null;

        return match ($action) {
            PlaybackAction::Play, PlaybackAction::Pause, PlaybackAction::Seek => $action,
            PlaybackAction::Join, PlaybackAction::Leave, null => null,
        };
    }

    /** @param array<array-key, mixed> $payload */
    private function handlePartyJoin(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'party.join requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $sessionUuid = $this->partyId($sessionId);
            $userUuid = Uuid::fromString($userId);
            $userPublicId = $this->identities->userPublicId($userUuid) ?? throw new \UnexpectedValueException('User not found.');
        } catch (InvalidArgumentException $e) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid public identity',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Identity lookup unavailable',
            ]);

            return;
        }

        try {
            $envelope = $this->bus->dispatch(
                new JoinPartySessionCommand($userUuid, $sessionUuid),
            );
            // ResultStampMiddleware on the default bus stamps the handler's PartyMember.
            $member = $envelope->last(PartyMemberResultStamp::class)?->getMember()
                ?? throw new \UnexpectedValueException('The join handler result carries no PartyMemberResultStamp.');
        } catch (HandlerFailedException $e) {
            // The handler answers not found to hide the party from a user who is neither a member
            // of it nor invited to it: that user gets the answer an unknown party gets.
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => HandlerFailure::cause($e) instanceof NotFoundException
                    ? 'Invalid public identity'
                    : 'Party operation refused',
            ]);

            return;
        } catch (Throwable $e) {
            $this->logger?->error('Party join failed', ['fd' => $fd, 'userId' => $userId, 'sessionId' => $sessionId, 'exception' => $e]);
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Failed to join party session',
            ]);

            return;
        }

        // Add to Swoole Table room for broadcasting. A refused membership keeps the
        // party membership: the user may already be a member through another
        // connection, so leaving the party here could remove that membership.
        $room = WebSocketRoomPolicy::partyRoom($sessionUuid->toString());
        if (!$this->joinBroadcastRoom($fd, $userId, $room)) {
            return;
        }
        if (!$this->staysInPartyRoom($fd, $room, $sessionId, $userUuid)) {
            return;
        }
        $this->logger?->info('Party joined', ['fd' => $fd, 'userId' => $userId, 'sessionId' => $sessionId, 'role' => $member->getRole()->value]);

        $this->pusher->pushToConnection($fd, [
            'type' => 'party.joined',
            'sessionId' => $sessionId,
            'role' => $member->getRole()->value,
        ]);

        // Broadcast member event to room
        $this->pusher->broadcast($room, [
            'type' => 'party.member_event',
            'sessionId' => $sessionId,
            'action' => 'join',
            'userId' => $userPublicId->toString(),
            'role' => $member->getRole()->value,
        ]);
    }

    /**
     * Whether the user's member row is still stored now that the socket is in the party's room.
     * When the row is gone, the socket leaves the room again and is answered as for an unknown
     * party.
     *
     * The host can revoke the member once the join has committed. The revoke takes the member's
     * sockets out of the room, but a socket that enters only after that would stay and keep
     * receiving the party's broadcasts. When the row cannot be read, the socket leaves the room
     * as well, so none stays in a room without a member row known to be behind it.
     */
    private function staysInPartyRoom(int $fd, string $room, string $partyPublicId, Uuid $userId): bool
    {
        try {
            $refusal = $this->identities->partyIdForMember(PublicId::fromString($partyPublicId), $userId) === null
                ? 'Invalid public identity'
                : null;
        } catch (Throwable) {
            $refusal = 'Identity lookup unavailable';
        }
        if ($refusal === null) {
            return true;
        }

        $this->registry->leaveRoom($room, $fd);
        $this->pusher->pushToConnection($fd, [
            'type' => 'error',
            'message' => $refusal,
        ]);

        return false;
    }

    /** @param array<array-key, mixed> $payload */
    private function handlePartyLeave(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'party.leave requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $userUuid = Uuid::fromString($userId);
            $sessionUuid = $this->partyIdForMember($sessionId, $userUuid);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid public identity',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Identity lookup unavailable',
            ]);

            return;
        }

        try {
            $this->bus->dispatch(
                new LeavePartySessionCommand($userUuid, $sessionUuid),
            );
        } catch (HandlerFailedException $e) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Party operation refused',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Failed to leave party session',
            ]);

            return;
        }

        // The leave handler's MemberLeft takes every socket of the user out of the
        // party room and tells the members left in it, as for a leave over HTTP.
        $this->logger?->info('Party left', ['fd' => $fd, 'userId' => $userId, 'sessionId' => $sessionId]);
    }

    /** @param array<array-key, mixed> $payload */
    private function handlePartyPlayback(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;

        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'party.playback requires a "sessionId" field',
            ]);

            return;
        }

        // The whole payload is checked before the party is looked up, so a malformed message
        // gets the same answer whether or not the party exists.
        $playbackAction = $this->steeringAction($payload['action'] ?? null);
        // A position that is absent or null means the handler keeps the current one.
        $position = $payload['position'] ?? null;
        if ($playbackAction === null || ($position !== null && !is_int($position) && !is_float($position))) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid sessionId or action',
            ]);

            return;
        }

        try {
            $userUuid = Uuid::fromString($userId);
            $sessionUuid = $this->partyIdForMember($sessionId, $userUuid);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid sessionId or action',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Identity lookup unavailable',
            ]);

            return;
        }

        try {
            switch ($playbackAction) {
                case PlaybackAction::Play:
                    $this->logger?->debug('Playback play', ['fd' => $fd, 'sessionId' => $sessionId, 'userId' => $userId]);
                    $this->bus->dispatch(
                        new StartPlaybackCommand($sessionUuid, $userUuid, $position),
                    );
                    break;

                case PlaybackAction::Pause:
                    $this->bus->dispatch(
                        new PausePlaybackCommand($sessionUuid, $userUuid),
                    );
                    break;

                case PlaybackAction::Seek:
                    $this->bus->dispatch(
                        new SeekPlaybackCommand($sessionUuid, $userUuid, $position ?? 0.0),
                    );
                    break;
            }
        } catch (HandlerFailedException $e) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Party operation refused',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Playback action failed',
            ]);
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function handlePartySync(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        $position = array_key_exists('position', $payload) ? $payload['position'] : 0.0;
        $latency = array_key_exists('latency', $payload) ? $payload['latency'] : 0.0;

        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'party.sync requires a "sessionId" field',
            ]);

            return;
        }

        if (
            (!is_int($position) && !is_float($position))
            || !is_finite((float) $position)
            || $position < 0
            || (!is_int($latency) && !is_float($latency))
            || !is_finite((float) $latency)
            || $latency < 0
            || $latency > 10
        ) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid position or latency',
            ]);

            return;
        }

        try {
            $userUuid = Uuid::fromString($userId);
            $sessionUuid = $this->partyIdForMember($sessionId, $userUuid);
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid sessionId format',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Identity lookup unavailable',
            ]);

            return;
        }

        try {
            $envelope = $this->bus->dispatch(
                new SyncPlaybackCommand(
                    $sessionUuid,
                    $userUuid,
                    (float) $position,
                    (float) $latency,
                ),
            );
            $serverPosition = $envelope->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)?->getResult();
            if (
                (!is_int($serverPosition) && !is_float($serverPosition))
                || !is_finite((float) $serverPosition)
                || $serverPosition < 0
            ) {
                throw new \UnexpectedValueException('Sync handler did not return a valid position.');
            }
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Sync failed',
            ]);

            return;
        }

        $this->pusher->pushToConnection($fd, [
            'type' => 'party.sync_response',
            'sessionId' => $sessionId,
            'serverPosition' => $serverPosition,
        ]);
    }

    /** @var array<int,array{incarnationId:string,capability:string}> */
    private array $playbackEndpoints = [];

    /** @param array<array-key,mixed> $payload */
    private function handlePlaybackCoordination(int $fd, string $userId, array $payload, string $operation): void
    {
        $generation = $this->connectionGenerations[$fd] ?? null;
        try {
            $bound = $this->playbackEndpoints[$fd] ?? null;
            if (in_array($operation, ['ack', 'observe', 'snapshot'], true) && $bound !== null) {
                if (isset($payload['incarnationId']) && $payload['incarnationId'] !== $bound['incarnationId']) {
                    throw new \DomainException('This connection does not own the endpoint.');
                }
                $payload['incarnationId'] = $bound['incarnationId'];
            }
            if (in_array($operation, ['ack', 'observe'], true) && $bound === null) {
                throw new \DomainException('Register an endpoint on this connection first.');
            }
            $owner = Uuid::fromString($userId);
            $result = $this->listeningSessions->coordinate($owner, $operation, $payload, $bound['capability'] ?? null);
            if (!$this->isCurrentConnection($fd, $generation)) {
                return;
            }
            if ($operation === 'endpoint' && isset($result['endpoint'])) {
                $this->playbackEndpoints[$fd] = ['incarnationId' => $result['endpoint']['incarnationId'], 'capability' => $result['endpoint']['capability']];
            }
            $endpoint = $this->playbackEndpoints[$fd] ?? null;
            $this->listeningSessions->bindPlayback($fd, $owner, $endpoint['incarnationId'] ?? null, $endpoint['capability'] ?? null);
            $this->pusher->pushToConnection($fd, ['type' => 'session.snapshot', 'data' => $result]);
        } catch (\Throwable $error) {
            if (!$this->isCurrentConnection($fd, $generation)) {
                return;
            }
            $this->pusher->pushToConnection($fd, ['type' => 'session.error', 'message' => $error instanceof \App\Shared\Application\Exception\ConflictException || $error instanceof \App\Shared\Application\Exception\InvalidInputException || $error instanceof \DomainException || $error instanceof \InvalidArgumentException ? $error->getMessage() : 'Playback coordination failed.']);
        }
    }

    private function isCurrentConnection(int $fd, ?int $generation): bool
    {
        return $generation !== null && ($this->connectionGenerations[$fd] ?? null) === $generation;
    }

    public function onClose(int $fd): void
    {
        $userId = $this->fdUsers[$fd] ?? null;
        $this->liveSubscriptions->unsubscribe($fd);
        $this->listeningSessions->disconnectPlayback($fd);
        $this->registry->removeConnection($fd);
        unset($this->fdUsers[$fd], $this->rateLimitWindows[$fd], $this->closingFds[$fd], $this->playbackEndpoints[$fd], $this->connectionGenerations[$fd]);

        $this->logger?->debug('WebSocket closed', ['fd' => $fd, 'userId' => $userId]);
    }

    /** @param array<array-key, mixed> $payload */
    private function handleTranscodePosition(int $fd, string $userId, array $payload): void
    {
        $sessionId = $payload['sessionId'] ?? null;
        $position = $payload['position'] ?? null;
        $action = $payload['action'] ?? 'seek';

        if (!is_string($sessionId) || $sessionId === '') {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'transcode.position requires a "sessionId" field',
            ]);

            return;
        }

        try {
            $session = $this->identities->transcodeSessionId(PublicId::fromString($sessionId), Uuid::fromString($userId));
            if ($session === null) {
                throw new InvalidArgumentException('Session not found.');
            }
            $sessionUuid = $session;
        } catch (InvalidArgumentException) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Invalid sessionId format',
            ]);

            return;
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Identity lookup unavailable',
            ]);

            return;
        }

        $generation = $payload['generation'] ?? null;
        $revision = $payload['revision'] ?? null;
        if ((!is_int($position) && !is_float($position)) || !is_finite((float) $position) || $position < 0
            || !in_array($action, ['seek', 'pause', 'resume', 'progress'], true)) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Position must be a nonnegative number and action must be seek, pause, resume, or progress.',
            ]);

            return;
        }
        if ($action === 'seek' && array_key_exists('generation', $payload) && (!is_int($generation) || $generation < 1)) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Seek generation must be a positive integer when provided.',
            ]);

            return;
        }
        if ($action === 'progress' && (!is_int($generation) || $generation < 1 || !is_int($revision) || $revision < 1)) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Progress requires the current positive generation and revision.',
            ]);

            return;
        }
        $requestId = $payload['requestId'] ?? null;
        if (array_key_exists('requestId', $payload)) {
            try {
                if (!is_string($requestId)) {
                    throw new InvalidArgumentException('Invalid request identity.');
                }
                Uuid::fromString($requestId);
            } catch (InvalidArgumentException) {
                $this->pusher->pushToConnection($fd, [
                    'type' => 'error',
                    'message' => 'Request identity must be a UUID when provided.',
                ]);

                return;
            }
        }
        $position = (float) $position;

        try {
            $this->bus->dispatch(new UpdateTranscodePositionCommand(
                sessionId: $sessionUuid,
                position: $position,
                action: $action,
                requestId: $requestId,
                expectedGeneration: in_array($action, ['seek', 'progress'], true) ? $generation : null,
                expectedRevision: $action === 'progress' ? $revision : null,
            ));
        } catch (Throwable) {
            $this->pusher->pushToConnection($fd, [
                'type' => 'error',
                'message' => 'Failed to update transcode position',
            ]);

            return;
        }

        $acknowledgement = [
            'type' => 'transcode.position_ack',
            'sessionId' => $sessionId,
            'position' => $position,
            'status' => 'processed',
        ];
        if ($requestId !== null) {
            $acknowledgement['requestId'] = $requestId;
        }
        $this->pusher->pushToConnection($fd, $acknowledgement);
    }
}
