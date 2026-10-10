<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\EventListener;

use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Shared\Application\Port\LivePartyRoomsPortInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Takes a member who left, over HTTP or the WebSocket, out of the party's live room,
 * and empties the room when the party ends, so the room only reaches members.
 */
final readonly class LivePartyRoomListener
{
    public function __construct(
        private LivePartyRoomsPortInterface $rooms,
    ) {
    }

    #[AsEventListener(event: MemberLeft::class)]
    public function onMemberLeft(MemberLeft $event): void
    {
        $this->rooms->removeMember($event->getSessionId(), $event->getUserId());
    }

    #[AsEventListener(event: PartySessionEnded::class)]
    public function onSessionEnded(PartySessionEnded $event): void
    {
        $this->rooms->close($event->getSessionId());
    }
}
