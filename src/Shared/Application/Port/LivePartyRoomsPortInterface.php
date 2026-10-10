<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps a party's live WebSocket room in step with the party's membership: the room
 * holds members' sockets only.
 *
 * Sockets live in the running web server, so the calls act only inside one of its
 * HTTP workers. Anywhere else (a console command, a Messenger worker, a test kernel)
 * there is no room to change and they do nothing.
 */
interface LivePartyRoomsPortInterface
{
    /**
     * Removes every socket of the user from the party's room, then sends the sockets
     * left in it one `party.member_event` leave event.
     */
    public function removeMember(Uuid $sessionId, Uuid $userId): void;

    /** Removes every socket from the party's room. */
    public function close(Uuid $sessionId): void;
}
