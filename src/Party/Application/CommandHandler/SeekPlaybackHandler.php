<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\SeekPlaybackCommand;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SeekPlaybackHandler
{
    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SeekPlaybackCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        if ($session->getHostUserId()->toString() !== $command->getUserId()->toString()) {
            return;
        }

        $this->sessionPort->seekTo($command->getSessionId(), $command->getPosition());

        $this->eventDispatcher->dispatch(new PlaybackPositionChanged(
            jobId: $session->getTranscodeJobId(),
            position: $command->getPosition(),
            action: 'seek',
        ));
    }
}
