<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\EndPartySessionCommand;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PartySessionEnded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class EndPartySessionHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws AccessDeniedException when the caller is not the party's host
     */
    #[AsMessageHandler]
    public function __invoke(EndPartySessionCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        if (!$session->getHostUserId()->equals($command->getUserId())) {
            throw new AccessDeniedException('Only the host may end the party.');
        }

        $session->endSession();
        $this->sessionPort->save($session);

        $this->eventDispatcher->dispatch(new PartySessionEnded(
            sessionId: $session->getId(),
            hostUserId: $session->getHostUserId(),
        ));
    }
}
