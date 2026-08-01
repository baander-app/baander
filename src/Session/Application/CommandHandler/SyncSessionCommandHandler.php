<?php

declare(strict_types=1);

namespace App\Session\Application\CommandHandler;

use App\Session\Application\Command\SyncSessionCommand;
use App\Session\Application\Port\SessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncSessionCommandHandler
{
    public function __construct(
        private readonly SessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(SyncSessionCommand $command): array
    {
        return $this->sessionPort->syncSession(
            userId: $command->getUserId(),
            deviceId: $command->getDeviceId(),
            queue: $command->getQueue(),
            currentTrackIndex: $command->getCurrentTrackIndex(),
            position: $command->getPosition(),
            playbackState: $command->getPlaybackState(),
        );
    }
}
