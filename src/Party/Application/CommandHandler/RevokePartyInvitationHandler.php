<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\RevokePartyInvitationCommand;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Lets the party's host take a user out of it: the user's invitation and member row are both
 * deleted. Revoking a user who holds neither changes nothing. A removed member who owns the
 * party's transcode job takes it along: nobody left in the party steers their encoder.
 *
 * The revoke runs under the party's row lock and decides on the host as stored once its turn
 * comes. The user is resolved only after the caller is known to be the host, so a caller who is
 * not learns nothing about which users exist. The host cannot name themself, so the host role
 * never stays with a removed member. MemberLeft leaves after the commit, and only when a member
 * row was deleted: the live room drops the member's sockets on it, and dropped before the delete
 * is visible, a socket joining meanwhile would stay in the room.
 */
final readonly class RevokePartyInvitationHandler
{
    public function __construct(
        private PartySessionPortInterface $sessionPort,
        private PartyInvitationPortInterface $invitationPort,
        private PartyMemberPortInterface $memberPort,
        private PartyMediaAccessPortInterface $mediaAccess,
        private PartyPublicIdentityPortInterface $identities,
        private TransactionPortInterface $transaction,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws PartyNotFoundException when the party does not exist
     * @throws AccessDeniedException  when the caller is not the party's host
     * @throws NotFoundException      when no user has the named public ID
     * @throws InvalidInputException  when the host names themself
     */
    #[AsMessageHandler]
    public function __invoke(RevokePartyInvitationCommand $command): void
    {
        $left = $this->transaction->run(fn (): ?MemberLeft => $this->revoke($command));

        if ($left !== null) {
            $this->eventDispatcher->dispatch($left);
        }
    }

    /** @return MemberLeft|null the leave to announce, when the user held a member row */
    private function revoke(RevokePartyInvitationCommand $command): ?MemberLeft
    {
        $sessionId = $command->getSessionId();
        $hostUserId = $command->getHostUserId();

        $session = $this->sessionPort->lockByUuid($sessionId) ?? throw new PartyNotFoundException();
        if (!$session->getHostUserId()->equals($hostUserId)) {
            throw new AccessDeniedException('Only the host may manage the party\'s invitations.');
        }

        $userId = $this->identities->userId($command->getUserPublicId());
        if ($userId->equals($hostUserId)) {
            throw new InvalidInputException('The host cannot revoke their own invitation.');
        }

        $this->invitationPort->revoke($userId, $sessionId);
        if (!$this->memberPort->removeMember($userId, $sessionId)) {
            return null;
        }

        $this->releaseJobOwnedBy($session, $userId);

        return new MemberLeft(sessionId: $sessionId, userId: $userId);
    }

    private function releaseJobOwnedBy(SyncedPartySession $session, Uuid $userId): void
    {
        $jobId = $session->getTranscodeJobId();
        if ($jobId === null || !$this->mediaAccess->ownsTranscodeJob($userId, $jobId)) {
            return;
        }

        $session->releaseTranscodeJob();
        $this->sessionPort->save($session);
    }
}
