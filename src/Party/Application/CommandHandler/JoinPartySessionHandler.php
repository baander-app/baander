<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lets a user into a party: a member comes back to their member row, and a user the host has
 * invited gets one.
 *
 * The join runs under the party's row lock and decides on the member row and the invitation as
 * they are stored once its turn comes, so a revoke that has answered holds against it. A user
 * who holds neither is answered as for a party that does not exist, and that is decided before
 * the party's state is looked at: whether the party has ended or is full shows only to a user it
 * admits. A returning member already holds a place, so a full party still takes them back.
 * MemberJoined leaves after the commit, and only for a new member.
 */
final readonly class JoinPartySessionHandler
{
    public function __construct(
        private PartySessionPortInterface $sessionPort,
        private PartyMemberPortInterface $memberPort,
        private PartyInvitationPortInterface $invitationPort,
        private TransactionPortInterface $transaction,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws PartyNotFoundException when the party does not exist, or the user is neither a member of it nor invited to it
     * @throws ConflictException      when the party has ended, or is full and the user is not a member yet
     */
    #[AsMessageHandler]
    public function __invoke(JoinPartySessionCommand $command): PartyMember
    {
        [$member, $joined] = $this->transaction->run(
            fn (): array => $this->join($command->getSessionId(), $command->getUserId()),
        );

        if ($joined !== null) {
            $this->eventDispatcher->dispatch($joined);
        }

        return $member;
    }

    /** @return array{PartyMember, MemberJoined|null} the user's member row, and the join to announce when the row is new */
    private function join(Uuid $sessionId, Uuid $userId): array
    {
        $session = $this->sessionPort->lockByUuid($sessionId) ?? throw new PartyNotFoundException();

        $member = $this->memberPort->findByUserAndSession($userId, $sessionId);
        if ($member !== null) {
            $this->refuseAnEndedParty($session);
            $member->reconnect();
            $this->memberPort->save($member);

            return [$member, null];
        }

        if (!$this->invitationPort->isInvited($userId, $sessionId)) {
            throw new PartyNotFoundException();
        }
        $this->refuseAnEndedParty($session);
        if ($this->memberPort->countBySession($sessionId) >= $session->getMaxMembers()) {
            throw new ConflictException('The party is full.');
        }

        return [
            $this->memberPort->addMember($userId, $sessionId),
            new MemberJoined(sessionId: $sessionId, userId: $userId, role: MemberRole::Member->value),
        ];
    }

    private function refuseAnEndedParty(SyncedPartySession $session): void
    {
        if (!$session->isActive()) {
            throw new ConflictException('The party has ended.');
        }
    }
}
