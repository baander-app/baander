<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Hands the host role to another member of the party, on the session and on both member rows.
 *
 * The transfer runs under the party's row lock and decides on the host and the members as they
 * are stored once its turn comes. The target is resolved only after the caller is known to be
 * the host, so a caller who is not learns nothing about which users exist.
 */
final readonly class TransferHostHandler
{
    public function __construct(
        private PartySessionPortInterface $sessionPort,
        private PartyMemberPortInterface $memberPort,
        private PartyPublicIdentityPortInterface $identities,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws PartyNotFoundException when the party does not exist
     * @throws AccessDeniedException  when the caller is not the party's host
     * @throws NotFoundException      when no user has the target's public ID
     * @throws InvalidInputException  when the target is the host already or is not a member
     */
    #[AsMessageHandler]
    public function __invoke(TransferHostCommand $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $sessionId = $command->getSessionId();
            $hostUserId = $command->getCurrentHostUserId();

            $session = $this->sessionPort->lockByUuid($sessionId) ?? throw new PartyNotFoundException();
            if (!$session->getHostUserId()->equals($hostUserId)) {
                throw new AccessDeniedException('Only the host may transfer the party.');
            }

            $newHostUserId = $this->identities->userId($command->getNewHostPublicId());
            if ($newHostUserId->equals($hostUserId)) {
                throw new InvalidInputException('The new host already hosts the party.');
            }
            $newHost = $this->memberPort->findByUserAndSession($newHostUserId, $sessionId)
                ?? throw new InvalidInputException('The new host is not a party member.');

            $session->transferHost($newHostUserId);
            $this->sessionPort->save($session);
            $newHost->promoteToHost();
            $this->memberPort->save($newHost);

            $oldHost = $this->memberPort->findByUserAndSession($hostUserId, $sessionId);
            if ($oldHost !== null) {
                $oldHost->demoteToMember();
                $this->memberPort->save($oldHost);
            }
        });
    }
}
