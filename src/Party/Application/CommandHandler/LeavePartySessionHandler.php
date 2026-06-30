<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PartySessionEnded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class LeavePartySessionHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(LeavePartySessionCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        $leavingUserId = $command->getUserId();
        $isHost = $session->getHostUserId()->toString() === $leavingUserId->toString();

        $this->memberPort->removeMember($leavingUserId, $command->getSessionId());

        if ($isHost) {
            $remainingMembers = $this->memberPort->findBySession($command->getSessionId());

            if (count($remainingMembers) === 0) {
                $session->endSession();
                $this->sessionPort->save($session);

                $this->eventDispatcher->dispatch(new PartySessionEnded(
                    sessionId: $session->getId(),
                    hostUserId: $session->getHostUserId(),
                ));

                return;
            }

            // Promote the earliest-joined remaining member to host
            usort($remainingMembers, fn ($a, $b) => $a->getJoinedAt() <=> $b->getJoinedAt());
            $newHost = $remainingMembers[0];
            $newHost->promoteToHost();
            $this->memberPort->save($newHost);
        }
    }
}
