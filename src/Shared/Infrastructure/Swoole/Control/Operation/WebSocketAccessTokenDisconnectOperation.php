<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use InvalidArgumentException;

/**
 * Closes the WebSocket connections that authenticated with given access tokens.
 *
 * A logout or a token revocation revokes a chain and then ends the sessions its
 * access tokens opened. The connection table is shared by every worker, and a
 * process-mode server closes any worker's connection from the accepting worker, so
 * one worker does the whole job.
 */
final readonly class WebSocketAccessTokenDisconnectOperation implements ServerControlOperation
{
    public const string NAME = 'websocket.access_token.disconnect';

    public function __construct(
        private WebSocketConnectionRegistry $registry,
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
        $accessTokenIds = $payload['access_token_ids'] ?? null;
        if (!is_array($accessTokenIds) || $accessTokenIds === []) {
            throw new InvalidArgumentException('"access_token_ids" must be a non-empty list of access token ids.');
        }

        foreach ($accessTokenIds as $accessTokenId) {
            if (!is_string($accessTokenId) || $accessTokenId === '') {
                throw new InvalidArgumentException('"access_token_ids" must be a non-empty list of access token ids.');
            }
        }

        $closed = 0;
        foreach ($this->registry->getConnectionFdsForAccessTokens(array_values($accessTokenIds)) as $fd) {
            if ($this->pusher->close($fd, WebSocketUserDisconnectOperation::CLOSE_CODE, WebSocketUserDisconnectOperation::CLOSE_REASON)) {
                ++$closed;
            }
        }

        return ['closed' => $closed];
    }
}
