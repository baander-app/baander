<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use InvalidArgumentException;

/**
 * Closes a user's WebSocket connections and voids the user's reconnection tokens.
 *
 * The connection and token tables are shared by every worker, and a process-mode
 * server closes any worker's connection from the accepting worker, so one worker
 * does the whole job.
 */
final readonly class WebSocketUserDisconnectOperation implements ServerControlOperation
{
    public const string NAME = 'websocket.user.disconnect';

    /** RFC 6455 policy violation: the server ended the session on purpose. */
    public const int CLOSE_CODE = 1008;

    public const string CLOSE_REASON = 'Session ended';

    public function __construct(
        private WebSocketPusher $pusher,
        private ReconnectionTokenService $reconnectionTokens,
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

    /** @return array{closed: int, reconnect_tokens_revoked: int} */
    public function handle(array $payload): array
    {
        $userId = $payload['user_id'] ?? null;
        if (!is_string($userId)) {
            throw new InvalidArgumentException('"user_id" must be a user UUID.');
        }
        $userId = Uuid::fromString($userId)->toString();

        // Tokens first, so no connection can take on the user's identity through
        // auth.reconnect while the user's connections close.
        $revoked = $this->reconnectionTokens->revokeForUser($userId);

        return [
            'closed' => $this->pusher->disconnectUser($userId, self::CLOSE_CODE, self::CLOSE_REASON),
            'reconnect_tokens_revoked' => $revoked,
        ];
    }
}
