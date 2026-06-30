<?php

declare(strict_types=1);

namespace App\Session\Application\CommandHandler;

use App\Session\Application\Command\SessionPlaybackCommand;
use App\Session\Application\Port\SessionPortInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SessionPlaybackCommandHandler
{
    public function __construct(
        private readonly SessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(SessionPlaybackCommand $command): array
    {
        return match ($command->getAction()) {
            'play', 'pause', 'seek' => $this->sessionPort->syncSession(
                userId: $command->getUserId(),
                deviceId: $command->getDeviceId(),
                queue: $command->getQueue() ?? [],
                currentTrackIndex: $command->getCurrentTrackIndex() ?? 0,
                position: $command->getPosition() ?? 0.0,
                playbackState: $command->getPlaybackState()
                    ?? ($command->getAction() === 'play' ? 'playing' : 'paused'),
            ),
            default => throw new RuntimeException(
                sprintf('Unknown playback action: "%s"', $command->getAction()),
            ),
        };
    }
}
