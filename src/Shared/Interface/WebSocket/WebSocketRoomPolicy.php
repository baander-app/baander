<?php

declare(strict_types=1);

namespace App\Shared\Interface\WebSocket;

/**
 * Names the WebSocket broadcast rooms and decides who may join them.
 *
 * Rooms in use:
 *
 * | Room | Joined by | Broadcasts |
 * |------|-----------|------------|
 * | `party:{sessionId}` | `party.join`, after JoinPartySessionCommand accepts the user as a member; left by `party.leave` | `party.member_event` from WebSocketController |
 *
 * Everything else reaches users through WebSocketPusher::push(), which needs no room.
 * A room's members receive its broadcasts, so a client never joins one by name with
 * `room.join`: a party room only through `party.join`, and no other room exists.
 * A new room kind gets its join rule here.
 */
final class WebSocketRoomPolicy
{
    private const string PARTY_PREFIX = 'party:';

    public static function partyRoom(string $sessionId): string
    {
        return self::PARTY_PREFIX . $sessionId;
    }

    /** Why a client may not join the room with `room.join`. No room accepts it. */
    public static function clientJoinRefusal(string $room): string
    {
        if (str_starts_with($room, self::PARTY_PREFIX)) {
            return 'Party rooms are joined with party.join';
        }

        return 'Unknown room';
    }
}
