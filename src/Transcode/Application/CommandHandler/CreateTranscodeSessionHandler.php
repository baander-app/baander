<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class CreateTranscodeSessionHandler
{
    private const int STARTUP_POLLS = 50;

    public function __construct(
        private TranscodeJobPortInterface $jobPort,
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeStoragePortInterface $storagePort,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
        private SleeperInterface $sleeper,
    ) {}

    #[AsMessageHandler]
    public function __invoke(CreateTranscodeSessionCommand $command): TranscodeSession
    {
        $outputDirectory = $this->storagePort->resolveJobDirectory($command->getVideoId(), $command->getQualityTier());
        $session = $this->transaction->run(function () use ($command, $outputDirectory): TranscodeSession {
            $this->ownership->lockAdmission($command->getVideoId());
            $job = $this->jobPort->getOrCreateJob($command->getVideoId(), $command->getQualityTier(), $outputDirectory, $command->getUserId(), $command->getAudioLanguages());
            $runtime = $this->ownership->find($job->getId());
            if ($runtime !== null) {
                $this->ownership->lockSession($runtime->sessionId);
                $job = $this->jobPort->findByUuid($job->getId()) ?? $job;
                $runtime = $this->ownership->find($job->getId());
            }
            if ($runtime !== null && ($runtime->requestId === $command->requestId || in_array($runtime->outcome, ['pending', 'accepted', 'ready', 'complete'], true))) {
                $existing = $this->sessionPort->findByUuid($runtime->sessionId);
                if ($existing !== null && $existing->getUserId()->equals($command->getUserId())) {
                    return $existing;
                }
            }
            if (in_array($job->getStatus(), [TranscodeStatus::Failed, TranscodeStatus::Cancelled], true)) {
                $job->retry();
            }
            $job->attachSession();
            $this->jobPort->save($job);
            $session = $this->sessionPort->createSession($command->getUserId(), $job->getId(), $command->getVideoId(), $command->getAudioProfile(), $command->getPriority(), $command->getAudioLanguages());
            $this->ownership->admit($job->getId(), $session->getId(), $command->requestId, new DesiredTranscodeState($command->getAudioProfile(), audioLanguages: array_values($command->getAudioLanguages())));
            return $session;
        });

        // The transaction is committed before a worker can accept the execution.
        for ($poll = 0; $poll < self::STARTUP_POLLS; $poll++) {
            $runtime = $this->ownership->find($session->getJobId());
            if ($runtime !== null && $runtime->sessionId->equals($session->getId())) {
                if ($runtime->outcome === 'complete' || ($runtime->ownershipLive && in_array($runtime->outcome, ['accepted', 'ready'], true))) {
                    return $session;
                }
                if (in_array($runtime->outcome, ['refused', 'failed', 'cancelled'], true)) {
                    throw new TranscodeStartupUnavailableException();
                }
            }
            $this->sleeper->sleep(0.1);
        }
        $expired = $this->ownership->expireAdmission($session->getJobId(), $session->getId(), $runtime->requestId ?? $command->requestId);
        if (!$expired) {
            $runtime = $this->ownership->find($session->getJobId());
            if ($runtime !== null && $runtime->sessionId->equals($session->getId()) && ($runtime->outcome === 'complete' || ($runtime->ownershipLive && in_array($runtime->outcome, ['accepted', 'ready'], true)))) {
                return $session;
            }
        }
        throw new TranscodeStartupUnavailableException();
    }
}
