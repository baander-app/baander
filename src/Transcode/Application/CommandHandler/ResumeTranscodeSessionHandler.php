<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\ResumeTranscodeSessionCommand;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ResumeTranscodeSessionHandler
{
    public function __construct(private TranscodePlaybackControl $playback) {}

    #[AsMessageHandler]
    public function __invoke(ResumeTranscodeSessionCommand $command): void
    {
        $this->playback->apply($command->getSessionId(), $command->requestId, status: 'running');
    }
}
