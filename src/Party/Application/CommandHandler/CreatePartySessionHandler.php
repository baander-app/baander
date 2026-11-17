<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\CreatePartySessionCommand;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Event\PartySessionCreated;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates a party whose creator is its host, holds a member row with the host role and holds an
 * invitation.
 *
 * The three rows are written in one transaction, so a party never exists without its host's
 * member row and invitation. The events leave after the commit.
 */
final readonly class CreatePartySessionHandler
{
    public function __construct(
        private PartySessionPortInterface $sessionPort,
        private PartyMemberPortInterface $memberPort,
        private EventDispatcherInterface $eventDispatcher,
        private PartyMediaAccessPortInterface $mediaAccess,
        private PartyInvitationPortInterface $invitationPort,
        private TransactionPortInterface $transaction,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreatePartySessionCommand $command): SyncedPartySession
    {
        $this->mediaAccess->assertHostCanPlay($command->getVideoId(), $command->getTranscodeJobId());

        $session = $this->transaction->run(fn (): SyncedPartySession => $this->create($command));

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

    private function create(CreatePartySessionCommand $command): SyncedPartySession
    {
        $session = $this->sessionPort->createSession(
            $command->getHostUserId(),
            $command->getVideoId(),
            $command->getTranscodeJobId(),
            $command->getMaxMembers(),
        );

        $member = $this->memberPort->addMember($command->getHostUserId(), $session->getId());
        $member->promoteToHost();
        $this->memberPort->save($member);

        // No row lock is taken: the party is not committed yet, so no other change of its
        // invitations can run beside this one.
        $this->invitationPort->invite($command->getHostUserId(), $session->getId());

        return $session;
    }
}
