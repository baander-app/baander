<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class UpdateTranscodePositionHandler
{
    public function __construct(private TranscodePlaybackControl $playback) {}

    #[AsMessageHandler]
    public function __invoke(UpdateTranscodePositionCommand $command): ?int
    {
        return $this->playback->apply(
            $command->sessionId,
            $command->requestId,
            status: match ($command->action) { 'pause' => 'paused', 'resume' => 'running', default => null },
            position: $command->position,
            seek: $command->action === 'seek',
            progress: $command->action === 'progress',
            expectedGeneration: $command->expectedGeneration,
            expectedRevision: $command->expectedRevision,
        );
    }
}
