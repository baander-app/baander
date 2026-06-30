<?php

declare(strict_types=1);

namespace App\Session\Application\CommandHandler;

use App\Session\Application\Command\ClaimSessionCommand;
use App\Session\Application\Port\SessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ClaimSessionCommandHandler
{
    public function __construct(
        private readonly SessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(ClaimSessionCommand $command): array
    {
        $result = $this->sessionPort->claimSession(
            userId: $command->getUserId(),
            deviceId: $command->getDeviceId(),
        );

        if ($command->getQueue() !== null) {
            $result = $this->sessionPort->syncSession(
                userId: $command->getUserId(),
                deviceId: $command->getDeviceId(),
                queue: $command->getQueue(),
                currentTrackIndex: $command->getCurrentTrackIndex() ?? 0,
                position: $command->getPosition() ?? 0.0,
                playbackState: 'paused',
            );
        }

        return $result;
    }
}
