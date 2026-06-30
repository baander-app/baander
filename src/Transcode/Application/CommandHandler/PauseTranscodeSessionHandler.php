<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\PauseTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PauseTranscodeSessionHandler
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessionPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(PauseTranscodeSessionCommand $command): void
    {
        $this->sessionPort->pauseSession($command->getSessionId());
    }
}
