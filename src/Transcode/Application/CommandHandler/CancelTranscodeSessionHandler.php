<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CancelTranscodeSessionCommand;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class CancelTranscodeSessionHandler
{
    public function __construct(private TranscodePlaybackControl $playback) {}

    #[AsMessageHandler]
    public function __invoke(CancelTranscodeSessionCommand $command): void
    {
        $this->playback->apply($command->getSessionId(), $command->requestId, status: 'cancelled', missingIsNoop: true);
    }
}
