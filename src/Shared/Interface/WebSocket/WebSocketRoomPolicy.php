<?php

declare(strict_types=1);

namespace App\Shared\Interface\WebSocket;

use App\Shared\Infrastructure\Swoole\WebSocketRooms;

/**
 * Names the WebSocket broadcast rooms and decides who may join them. The names
 * themselves come from WebSocketRooms, which SwooleLivePartyRooms shares.
 *
 * Rooms in use:
 *
 * | Room | Joined by | Left by | Broadcasts |
 * |------|-----------|---------|------------|
 * | `party:{sessionUuid}` | `party.join`, which names the party by public ID; the controller resolves it to the session UUID and joins the room after JoinPartySessionCommand accepts the user as a member | every socket of a member who leaves the party (`party.leave` or `POST /api/party/sessions/{publicId}/leave`); every socket when the party ends; one socket with `room.leave` or when it closes | `party.member_event` join from WebSocketController, leave from SwooleLivePartyRooms; `party.playback_state` from SwooleLivePartyRooms after the host plays, pauses or seeks |
 *
 * Everything else goes to individual connections and needs no room:
 * WebSocketPusher::pushToConnection() for replies and pushes, LiveFrameSender for `live.*`
 * frames.
 * A room's members receive its broadcasts, so a client never joins one by name with
 * `room.join`: a party room only through `party.join`, and no other room exists.
 * A new room kind gets its join rule here.
 */
final class WebSocketRoomPolicy
{
    public static function partyRoom(string $sessionId): string
    {
        return WebSocketRooms::party($sessionId);
    }

    /** Why a client may not join the room with `room.join`. No room accepts it. */
    public static function clientJoinRefusal(string $room): string
    {
        if (str_starts_with($room, WebSocketRooms::PARTY_PREFIX)) {
            return 'Party rooms are joined with party.join';
        }

        return 'Unknown room';
    }
}
