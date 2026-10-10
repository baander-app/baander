<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use InvalidArgumentException;

/**
 * Closes a user's WebSocket connections.
 *
 * The connection table is shared by every worker, and a process-mode server closes
 * any worker's connection from the accepting worker, so one worker does the whole
 * job.
 */
final readonly class WebSocketUserDisconnectOperation implements ServerControlOperation
{
    public const string NAME = 'websocket.user.disconnect';

    /** RFC 6455 policy violation: the server ended the session on purpose. */
    public const int CLOSE_CODE = 1008;

    public const string CLOSE_REASON = 'Session ended';

    public function __construct(
        private WebSocketPusher $pusher,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function fansOut(): bool
    {
        return false;
    }

    /** @return array{closed: int} */
    public function handle(array $payload): array
    {
        $userId = $payload['user_id'] ?? null;
        if (!is_string($userId)) {
            throw new InvalidArgumentException('"user_id" must be a user UUID.');
        }
        $userId = Uuid::fromString($userId)->toString();

        return ['closed' => $this->pusher->disconnectUser($userId, self::CLOSE_CODE, self::CLOSE_REASON)];
    }
}
