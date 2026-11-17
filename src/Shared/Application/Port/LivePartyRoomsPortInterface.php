<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use DateTimeImmutable;

/**
 * Keeps a party's live WebSocket room in step with the party's membership, so the room
 * holds members' sockets only, and tells those sockets when the host's playback changes.
 *
 * Sockets live in the running web server, so the calls act only inside one of its
 * HTTP workers. Anywhere else (a console command, a Messenger worker, a test kernel)
 * there is no room to change or send to, and they do nothing.
 */
interface LivePartyRoomsPortInterface
{
    /**
     * Removes every socket of the user from the party's room, then sends the sockets
     * left in it one `party.member_event` leave event.
     */
    public function removeMember(Uuid $sessionId, Uuid $userId, PublicId $sessionPublicId, PublicId $userPublicId): void;

    /** Removes every socket from the party's room. */
    public function close(Uuid $sessionId): void;

    /**
     * Sends every socket in the party's room one `party.playback_state` message with the
     * session's playback state right after the host played, paused or sought.
     */
    public function broadcastPlayback(
        Uuid $sessionId,
        PublicId $sessionPublicId,
        string $playbackState,
        float $currentPosition,
        float $wallClockPosition,
        DateTimeImmutable $updatedAt,
    ): void;
}
