<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class TransferHostHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(TransferHostCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            throw new \RuntimeException('Party session not found.');
        }
        if (!$session->getHostUserId()->equals($command->getCurrentHostUserId())) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Only the host may transfer the party.');
        }

        $newHostMember = $this->memberPort->findByUserAndSession(
            $command->getNewHostUserId(),
            $command->getSessionId(),
        );
        if ($newHostMember === null) {
            throw new \RuntimeException('The new host is not a party member.');
        }
        $this->sessionPort->transferHost($command->getSessionId(), $command->getNewHostUserId());
        $newHostMember->promoteToHost();
        $this->memberPort->save($newHostMember);

        $oldHostMember = $this->memberPort->findByUserAndSession(
            $command->getCurrentHostUserId(),
            $command->getSessionId(),
        );
        if ($oldHostMember !== null) {
            $oldHostMember->demoteToMember();
            $this->memberPort->save($oldHostMember);
        }
    }
}
