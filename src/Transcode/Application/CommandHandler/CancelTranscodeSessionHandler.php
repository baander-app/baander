<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CancelTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CancelTranscodeSessionHandler
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly TranscodeJobPortInterface $jobPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CancelTranscodeSessionCommand $command): void
    {
        $session = $this->sessionPort->findByUuid($command->getSessionId());
        if ($session === null) {
            return;
        }

        $session->markCancelled();
        $this->sessionPort->save($session);

        $this->detachSessionFromJob($session);
    }

    private function detachSessionFromJob(TranscodeSession $session): void
    {
        $job = $this->jobPort->findByUuid($session->getJobId());
        if ($job === null) {
            return;
        }

        $isOrphaned = $job->detachSession();
        $this->jobPort->save($job);

        if ($isOrphaned) {
            $job->markCancelled();
            $this->jobPort->save($job);
        }
    }
}
