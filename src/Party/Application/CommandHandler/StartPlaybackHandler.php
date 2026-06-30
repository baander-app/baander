<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\StartPlaybackCommand;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class StartPlaybackHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(StartPlaybackCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        if ($session->getHostUserId()->toString() !== $command->getUserId()->toString()) {
            return;
        }

        $position = $command->getPosition();

        $this->sessionPort->startPlayback($command->getSessionId(), $position);

        // Re-fetch to get the post-mutation state
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        $this->eventDispatcher->dispatch(new PlaybackPositionChanged(
            jobId: $session->getTranscodeJobId(),
            position: $position ?? $session->getCurrentPosition(),
            action: 'play',
        ));
    }
}
