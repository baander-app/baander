<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Application\Port\PartySessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SyncPlaybackHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SyncPlaybackCommand $command): float
    {
        return $this->sessionPort->syncPlayback(
            $command->getSessionId(),
            $command->getClientPosition(),
            $command->getClientLatency(),
        );
    }
}
