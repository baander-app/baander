<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\LivePartyRoomsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ServerWorkers;

/**
 * Changes party rooms in the connection registry. Its tables are shared by every
 * worker, so the worker that runs the leave or end changes sockets other workers
 * own without fanning out.
 */
final readonly class SwooleLivePartyRooms implements LivePartyRoomsPortInterface
{
    public function __construct(
        private ServerWorkers $workers,
        private WebSocketConnectionRegistry $registry,
        private WebSocketPusher $pusher,
    ) {
    }

    public function removeMember(Uuid $sessionId, Uuid $userId): void
    {
        if (!$this->insideTheServer()) {
            return;
        }

        $room = WebSocketRooms::party($sessionId->toString());
        foreach ($this->registry->getUserConnectionFds($userId->toString()) as $fd) {
            $this->registry->leaveRoom($room, $fd);
        }

        $this->pusher->broadcast($room, [
            'type'      => 'party.member_event',
            'sessionId' => $sessionId->toString(),
            'action'    => 'leave',
            'userId'    => $userId->toString(),
        ]);
    }

    public function close(Uuid $sessionId): void
    {
        if (!$this->insideTheServer()) {
            return;
        }

        $room = WebSocketRooms::party($sessionId->toString());
        foreach ($this->registry->getRoomMembers($room) as $fd) {
            $this->registry->leaveRoom($room, $fd);
        }
    }

    /**
     * A console process, Messenger worker or test kernel builds its own empty tables
     * and has no server to push through, so there is nothing to change there.
     */
    private function insideTheServer(): bool
    {
        return $this->workers->currentHttpWorkerId() !== null;
    }
}
