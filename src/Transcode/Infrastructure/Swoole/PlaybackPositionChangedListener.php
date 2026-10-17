<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use Symfony\Component\Messenger\MessageBusInterface;

/** Applies Party playback controls through the durable session use case. */
final readonly class PlaybackPositionChangedListener
{
    public function __construct(
        private TranscodeExecutionOwnershipPort $ownership,
        private MessageBusInterface $commandBus,
    ) {}

    public function __invoke(PlaybackPositionChanged $event): void
    {
        $runtime = $this->ownership->find($event->getJobId());
        if ($runtime === null) {
            return;
        }
        $this->commandBus->dispatch(new UpdateTranscodePositionCommand(
            $runtime->sessionId, $event->getPosition(), $event->getAction(),
        ));
    }
}
