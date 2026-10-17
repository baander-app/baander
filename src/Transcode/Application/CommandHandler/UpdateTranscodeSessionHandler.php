<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\UpdateTranscodeSessionCommand;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class UpdateTranscodeSessionHandler
{
    public function __construct(private TranscodePlaybackControl $playback) {}

    #[AsMessageHandler]
    public function __invoke(UpdateTranscodeSessionCommand $command): void
    {
        $this->playback->apply($command->sessionId, $command->requestId, audioProfile: $command->audioProfile);
    }
}
