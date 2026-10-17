<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CancelTranscodeSessionCommand;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\ValueObject\SessionState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;

final readonly class CancelTranscodeSessionHandler
{
    public function __construct(
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
        private TranscodeJobPortInterface $jobPort,
    ) {}

    #[AsMessageHandler]
    public function __invoke(CancelTranscodeSessionCommand $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $sessionId = $command->getSessionId();
            $this->ownership->lockSession($sessionId);
            $session = $this->sessionPort->findByUuid($sessionId);
            if ($session === null) {
                return;
            }
            $alreadyDetached = in_array($session->getSessionState(), [SessionState::Completed, SessionState::Failed, SessionState::Cancelled], true);
            $runtime = $this->ownership->control($session->getJobId(), $sessionId, $command->requestId, status: 'cancelled');
            if (in_array($runtime->outcome, ['complete', 'failed', 'refused'], true)) {
                return;
            }
            if ($runtime->desired->status === 'cancelled') {
                $session->markCancelled();
            }
            $session->updateAudioProfile($runtime->desired->audioProfile);
            $session->updateWallClockOffset($runtime->desired->position);
            $this->sessionPort->save($session);
            if (!$alreadyDetached && $runtime->desired->status === 'cancelled') {
                $job = $this->jobPort->findByUuid($session->getJobId());
                if ($job !== null) {
                    if ($job->detachSession()) {
                        $job->markCancelled();
                    }
                    $this->jobPort->save($job);
                }
            }
        });
    }
}
