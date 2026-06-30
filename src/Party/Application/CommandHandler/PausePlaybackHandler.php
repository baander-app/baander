<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\PausePlaybackCommand;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PausePlaybackHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(PausePlaybackCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        if ($session->getHostUserId()->toString() !== $command->getUserId()->toString()) {
            return;
        }

        $position = $session->getCurrentPosition();

        $this->sessionPort->pausePlayback($command->getSessionId());

        $this->eventDispatcher->dispatch(new PlaybackPositionChanged(
            jobId: $session->getTranscodeJobId(),
            position: $position,
            action: 'pause',
        ));
    }
}
