<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\FailInterruptedTranscodeSessionsCommand;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\ValueObject\SessionState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class FailInterruptedTranscodeSessionsHandler
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessionPort,
    ) {
    }

    /** @return int The number of sessions that failed */
    #[AsMessageHandler]
    public function __invoke(FailInterruptedTranscodeSessionsCommand $command): int
    {
        $failed = 0;
        foreach ($command->getJobIds() as $jobId) {
            foreach ($this->sessionPort->findByJob($jobId) as $session) {
                // The states CreateTranscodeSessionHandler reuses as a live session.
                if (!in_array($session->getSessionState(), [
                    SessionState::Pending,
                    SessionState::Preparing,
                    SessionState::Active,
                    SessionState::Paused,
                ], true)) {
                    continue;
                }
                $session->markFailed();
                $this->sessionPort->save($session);
                ++$failed;
            }
        }

        return $failed;
    }
}
