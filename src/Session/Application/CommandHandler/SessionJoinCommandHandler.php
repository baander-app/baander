<?php

declare(strict_types=1);

namespace App\Session\Application\CommandHandler;

use App\Session\Application\Command\SessionJoinCommand;
use App\Session\Application\Port\SessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SessionJoinCommandHandler
{
    public function __construct(
        private readonly SessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(SessionJoinCommand $command): array
    {
        $this->sessionPort->registerDevice(
            $command->getUserId(),
            $command->getDeviceId(),
            'Device',
        );

        $session = $this->sessionPort->getSession($command->getUserId());

        return $session ?? [];
    }
}
