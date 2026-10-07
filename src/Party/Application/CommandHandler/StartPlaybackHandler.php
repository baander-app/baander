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

        // Without a job (none was named, or it has been deleted) there is no encoder to steer;
        // the party's own playback state above still synchronizes its members.
        $jobId = $session->getTranscodeJobId();
        if ($jobId === null) {
            return;
        }

        $this->eventDispatcher->dispatch(new PlaybackPositionChanged(
            jobId: $jobId,
            position: $position ?? $session->getCurrentPosition(),
            action: 'play',
        ));
    }
}
