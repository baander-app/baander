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
        $this->sessionPort->transferHost($command->getSessionId(), $command->getNewHostUserId());

        $newHostMember = $this->memberPort->findByUserAndSession(
            $command->getNewHostUserId(),
            $command->getSessionId(),
        );
        if ($newHostMember !== null) {
            $newHostMember->promoteToHost();
            $this->memberPort->save($newHostMember);
        }

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
