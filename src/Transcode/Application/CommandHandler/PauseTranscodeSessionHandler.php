<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\PauseTranscodeSessionCommand;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Exception\SessionNotFoundException;
use App\Transcode\Domain\ValueObject\SessionState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class PauseTranscodeSessionHandler
{
    public function __construct(
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
    ) {}

    #[AsMessageHandler]
    public function __invoke(PauseTranscodeSessionCommand $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $sessionId = $command->getSessionId();
            $this->ownership->lockSession($sessionId);
            $session = $this->sessionPort->findByUuid($sessionId);
            if ($session === null) {
                throw SessionNotFoundException::forId($sessionId);
            }
            $runtime = $this->ownership->control($session->getJobId(), $sessionId, $command->requestId, status: 'paused');
            if (in_array($runtime->outcome, ['complete', 'failed', 'refused'], true)) {
                return;
            }
            if ($runtime->desired->status === 'cancelled') {
                $session->markCancelled();
            } elseif ($session->getSessionState() === SessionState::Active) {
                $session->markPaused();
            }
            $session->updateAudioProfile($runtime->desired->audioProfile);
            $session->updateWallClockOffset($runtime->desired->position);
            $this->sessionPort->save($session);
        });
    }
}
