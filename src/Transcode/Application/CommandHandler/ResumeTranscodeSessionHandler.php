<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\ResumeTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ResumeTranscodeSessionHandler
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessionPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ResumeTranscodeSessionCommand $command): void
    {
        $this->sessionPort->resumeSession($command->getSessionId());
    }
}
