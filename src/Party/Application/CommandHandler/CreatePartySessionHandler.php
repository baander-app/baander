<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\CreatePartySessionCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Event\PartySessionCreated;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreatePartySessionHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreatePartySessionCommand $command): SyncedPartySession
    {
        $session = $this->sessionPort->createSession(
            $command->getHostUserId(),
            $command->getVideoId(),
            $command->getTranscodeJobId(),
            $command->getMaxMembers(),
        );

        // Creator is automatically a member with HOST role
        $member = $this->memberPort->addMember($command->getHostUserId(), $session->getId());
        $member->promoteToHost();
        $this->memberPort->save($member);

        $this->eventDispatcher->dispatch(new PartySessionCreated(
            sessionId: $session->getId(),
            hostUserId: $command->getHostUserId(),
            videoId: $command->getVideoId(),
            maxMembers: $command->getMaxMembers(),
        ));

        $this->eventDispatcher->dispatch(new MemberJoined(
            sessionId: $session->getId(),
            userId: $command->getHostUserId(),
            role: MemberRole::Host->value,
        ));

        return $session;
    }
}
