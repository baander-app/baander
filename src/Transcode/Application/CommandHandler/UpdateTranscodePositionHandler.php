<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Exception\SessionNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class UpdateTranscodePositionHandler
{
    public function __construct(
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
    ) {}

    #[AsMessageHandler]
    public function __invoke(UpdateTranscodePositionCommand $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $sessionId = $command->sessionId;
            $this->ownership->lockSession($sessionId);
            $session = $this->sessionPort->findByUuid($sessionId);
            if ($session === null) {
                throw SessionNotFoundException::forId($sessionId);
            }
            $runtime = $this->ownership->control($session->getJobId(), $sessionId, $command->requestId, status: match ($command->action) { 'pause' => 'paused', 'resume' => 'running', default => null }, position: $command->position, seek: $command->action === 'seek');
            if (in_array($runtime->outcome, ['complete', 'failed', 'refused'], true)) {
                return;
            }
            if ($runtime->desired->status === 'cancelled') {
                $session->markCancelled();
            }
            $session->updateAudioProfile($runtime->desired->audioProfile);
            $session->updateWallClockOffset($runtime->desired->position);
            $this->sessionPort->save($session);
        });
    }
}
