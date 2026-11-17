<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\InvitePartyMemberCommand;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Lets the party's host invite a user to it. Inviting a user who is invited already changes
 * nothing.
 *
 * The invite runs under the party's row lock and decides on the host as stored once its turn
 * comes. The user is resolved only after the caller is known to be the host, so a caller who is
 * not learns nothing about which users exist.
 */
final readonly class InvitePartyMemberHandler
{
    public function __construct(
        private PartySessionPortInterface $sessionPort,
        private PartyInvitationPortInterface $invitationPort,
        private PartyPublicIdentityPortInterface $identities,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws PartyNotFoundException when the party does not exist
     * @throws AccessDeniedException  when the caller is not the party's host
     * @throws NotFoundException      when no user has the named public ID
     * @throws InvalidInputException  when the host names themself
     */
    #[AsMessageHandler]
    public function __invoke(InvitePartyMemberCommand $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $sessionId = $command->getSessionId();
            $hostUserId = $command->getHostUserId();

            $session = $this->sessionPort->lockByUuid($sessionId) ?? throw new PartyNotFoundException();
            if (!$session->getHostUserId()->equals($hostUserId)) {
                throw new AccessDeniedException('Only the host may manage the party\'s invitations.');
            }

            $userId = $this->identities->userId($command->getUserPublicId());
            if ($userId->equals($hostUserId)) {
                throw new InvalidInputException('The host cannot invite themself.');
            }

            $this->invitationPort->invite($userId, $sessionId);
        });
    }
}
