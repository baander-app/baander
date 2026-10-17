<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\PauseTranscodeSessionCommand;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class PauseTranscodeSessionHandler
{
    public function __construct(private TranscodePlaybackControl $playback) {}

    #[AsMessageHandler]
    public function __invoke(PauseTranscodeSessionCommand $command): void
    {
        $this->playback->apply($command->getSessionId(), $command->requestId, status: 'paused');
    }
}
