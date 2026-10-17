<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\EventListener;

use App\Party\Domain\Event\MemberLeft;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Shared\Application\Exception\NotFoundException;
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
        private PartySessionPortInterface $sessions,
        private PartyPublicIdentityPortInterface $identities,
    ) {
    }

    #[AsEventListener(event: MemberLeft::class)]
    public function onMemberLeft(MemberLeft $event): void
    {
        try {
            $session = $this->sessions->findByUuid($event->getSessionId()) ?? throw new NotFoundException('Party session not found.');
            $userPublicId = $this->identities->userPublicId($event->getUserId());
        } catch (\Throwable $error) {
            $this->rooms->close($event->getSessionId());
            throw $error;
        }

        $this->rooms->removeMember(
            $event->getSessionId(),
            $event->getUserId(),
            $session->getPublicId(),
            $userPublicId,
        );
    }

    #[AsEventListener(event: PartySessionEnded::class)]
    public function onSessionEnded(PartySessionEnded $event): void
    {
        $this->rooms->close($event->getSessionId());
    }
}
