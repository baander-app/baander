<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Names of the WebSocket broadcast rooms in WebSocketConnectionRegistry. Every
 * writer and reader of a room builds its name here, so they agree on it.
 */
final class WebSocketRooms
{
    public const string PARTY_PREFIX = 'party:';

    /**
     * The room of a party session. A client may send a UUID in either case, so the
     * name uses the lower-case form the database returns.
     */
    public static function party(string $sessionId): string
    {
        return self::PARTY_PREFIX . strtolower($sessionId);
    }
}
