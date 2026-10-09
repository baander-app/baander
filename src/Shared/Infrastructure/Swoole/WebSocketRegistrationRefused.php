<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * The connection registry would not record a connection or a room membership.
 * Nothing of the refused registration is stored; the caller refuses the connection
 * or the join.
 */
final class WebSocketRegistrationRefused extends \RuntimeException
{
    /** WebSocket close code 1008 (policy violation). */
    public const int CLOSE_POLICY_VIOLATION = 1008;
    /** WebSocket close code 1013 (try again later). */
    public const int CLOSE_TRY_AGAIN_LATER = 1013;

    private function __construct(
        string $message,
        public readonly int $closeCode,
        public readonly string $closeReason,
    ) {
        parent::__construct($message);
    }

    public static function userConnectionLimit(string $userId, int $limit): self
    {
        return new self(
            sprintf('User %s has reached the maximum of %d WebSocket connections.', $userId, $limit),
            self::CLOSE_POLICY_VIOLATION,
            'Too many connections for this user',
        );
    }

    public static function connectionTableFull(int $fd): self
    {
        return new self(
            sprintf('The WebSocket connection table has no free row for connection %d.', $fd),
            self::CLOSE_TRY_AGAIN_LATER,
            'Server connection limit reached',
        );
    }

    public static function roomTableFull(string $room, int $fd): self
    {
        return new self(
            sprintf('The WebSocket room tables have no free row for connection %d in room "%s".', $fd, $room),
            self::CLOSE_TRY_AGAIN_LATER,
            'Server room limit reached',
        );
    }
}
