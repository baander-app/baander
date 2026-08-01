<?php

declare(strict_types=1);

namespace App\Session\Application\CommandHandler;

use App\Session\Application\Command\CreateSessionCommand;
use App\Session\Application\Port\SessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CreateSessionCommandHandler
{
    public function __construct(
        private readonly SessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(CreateSessionCommand $command): array
    {
        return $this->sessionPort->createSession(
            userId: $command->getUserId(),
            queue: $command->getQueue(),
            currentTrackIndex: $command->getCurrentTrackIndex(),
            position: $command->getPosition(),
        );
    }
}
