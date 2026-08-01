<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLockInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\SessionState;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Shared\Infrastructure\Swoole\Async;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreateTranscodeSessionHandler
{
    private const LOOP_LOCK_TTL_SECONDS = 30;
    private const LOCK_WAIT_TIMEOUT_SECONDS = 5.0;
    private const LOCK_WAIT_INTERVAL_SECONDS = 0.1;

    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly TranscodeStoragePortInterface $storagePort,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TranscodeLoopLockInterface $loopLock,
    )
    {
    }

    #[AsMessageHandler]
    public function __invoke(CreateTranscodeSessionCommand $command): TranscodeSession
    {
        $outputDirectory = $this->storagePort->resolveJobDirectory(
            $command->getVideoId(),
            $command->getQualityTier(),
        );

        $job = $this->jobPort->getOrCreateJob(
            $command->getVideoId(),
            $command->getQualityTier(),
            $outputDirectory,
            $command->getAudioLanguages(),
        );

        // Set audio track languages on job if not already set
        if (empty($job->getAudioTrackLanguages()) && !empty($command->getAudioLanguages())) {
            $job->setAudioTrackLanguages($command->getAudioLanguages());
        }

        // A failed or cancelled job becomes runnable again when a new session
        // attaches — the encoding loop reuses any segments already on disk.
        $wasRetried = in_array($job->getStatus(), [TranscodeStatus::Failed, TranscodeStatus::Cancelled], true);
        if ($wasRetried) {
            $job->retry();
        }

        // Fast path: if a live session already exists, reuse it immediately.
        $liveSession = $this->findLiveSession($job->getId());
        if ($liveSession !== null) {
            return $liveSession;
        }

        // Only one worker may start the encoding loop for a job. Acquire a
        // distributed lock; if another worker is already starting it, wait
        // briefly for a live session to appear and then reuse it.
        if (!$this->loopLock->acquire($job->getId(), self::LOOP_LOCK_TTL_SECONDS)) {
            $liveSession = $this->waitForLiveSession($job->getId());
            if ($liveSession !== null) {
                return $liveSession;
            }

            // No session appeared — fallback to the stale-session recovery path
            // below, which may mark old sessions failed and start a new loop.
        }

        // We hold the lock. Re-check the DB: another worker may have created a
        // session between our fast-path check and acquiring the lock.
        $liveSession = $this->findLiveSession($job->getId());
        if ($liveSession !== null) {
            $this->loopLock->release($job->getId());

            return $liveSession;
        }

        // A retried job skips the stale-session recovery: old sessions are
        // leftovers from the failed run and a fresh loop must start.
        if (!$wasRetried) {
            foreach ($this->sessionPort->findByJob($job->getId()) as $existing) {
                $state = $existing->getSessionState();
                if (!in_array($state, [
                    SessionState::Pending,
                    SessionState::Preparing,
                    SessionState::Active,
                    SessionState::Paused,
                ], true)) {
                    continue;
                }

                $idleSeconds = (new \DateTimeImmutable())->getTimestamp() - $existing->getUpdatedAt()->getTimestamp();
                if ($idleSeconds > 60) {
                    $existing->markFailed();
                    $this->sessionPort->save($existing);
                }
            }
        }

        $job->attachSession();
        $this->jobPort->save($job);

        $session = $this->sessionPort->createSession(
            $command->getUserId(),
            $job->getId(),
            $command->getVideoId(),
            $command->getAudioProfile(),
            $command->getPriority(),
            $command->getAudioLanguages(),
        );

        $this->eventDispatcher->dispatch(new TranscodeSessionAttached(
            sessionId: $session->getId(),
            jobId: $job->getId(),
            userId: $command->getUserId(),
            qualityTier: $job->getQualityTierName(),
        ));

        return $session;
    }

    private function findLiveSession(\App\Shared\Domain\Model\Uuid $jobId): ?TranscodeSession
    {
        foreach ($this->sessionPort->findByJob($jobId) as $existing) {
            if (in_array($existing->getSessionState(), [
                SessionState::Pending,
                SessionState::Preparing,
                SessionState::Active,
                SessionState::Paused,
            ], true)) {
                $idleSeconds = (new \DateTimeImmutable())->getTimestamp() - $existing->getUpdatedAt()->getTimestamp();
                if ($idleSeconds <= 60) {
                    return $existing;
                }
            }
        }

        return null;
    }

    private function waitForLiveSession(\App\Shared\Domain\Model\Uuid $jobId): ?TranscodeSession
    {
        $elapsed = 0.0;
        while ($elapsed < self::LOCK_WAIT_TIMEOUT_SECONDS) {
            Async::sleep(self::LOCK_WAIT_INTERVAL_SECONDS);
            $elapsed += self::LOCK_WAIT_INTERVAL_SECONDS;

            $live = $this->findLiveSession($jobId);
            if ($live !== null) {
                return $live;
            }
        }

        return null;
    }
}
