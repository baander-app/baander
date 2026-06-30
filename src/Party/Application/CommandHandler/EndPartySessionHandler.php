<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\EndPartySessionCommand;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PartySessionEnded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class EndPartySessionHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(EndPartySessionCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        if ($session->getHostUserId()->toString() !== $command->getUserId()->toString()) {
            return;
        }

        $session->endSession();
        $this->sessionPort->save($session);

        $this->eventDispatcher->dispatch(new PartySessionEnded(
            sessionId: $session->getId(),
            hostUserId: $session->getHostUserId(),
        ));
    }
}
