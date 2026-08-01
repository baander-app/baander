<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use App\Transcode\Domain\Exception\SessionNotFoundException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateTranscodePositionHandler
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(UpdateTranscodePositionCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->sessionId);
        if ($session === null) {
            throw SessionNotFoundException::forId($command->sessionId);
        }

        $session->updateWallClockOffset($command->position);
        $this->sessionPort->save($session);

        $this->eventDispatcher->dispatch(new PlaybackPositionChanged(
            $session->getJobId(),
            $command->position,
            $command->action,
        ));
    }
}
