<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\ValueObject\SessionState;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Ends transcode sessions whose client can no longer be playing them.
 *
 * A client that leaves without cancelling keeps its session, and the session
 * keeps its job: the worker holds the execution at the lead throttle and renews
 * its lease for as long as the session exists. Expiry cancels the execution so
 * the worker lets go, ends the session and releases the job, which job cleanup
 * can then remove with its output.
 */
final readonly class StaleTranscodeSessionExpiry
{
    /**
     * The longest lifetime of a signed stream URL. Every playback report and
     * every signing touches the session, so a session untouched for this long
     * has no client holding a URL that still plays. A paused player sends no
     * reports but renews its URL before it ends, and so never expires.
     */
    public const int IDLE_SECONDS = 86400;

    private const array HOLDING_STATES = [SessionState::Pending, SessionState::Preparing, SessionState::Active, SessionState::Paused];

    /** Execution outcomes a worker still acts on. */
    private const array RUNNABLE_OUTCOMES = ['pending', 'accepted', 'ready'];

    public function __construct(
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeExecutionOwnershipPort $ownership,
        private TranscodeJobPortInterface $jobPort,
        private TransactionPortInterface $transaction,
        private ClockInterface $clock,
    ) {}

    /** @return int The number of sessions that expired */
    public function expire(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d seconds', self::IDLE_SECONDS));
        $expired = 0;
        foreach ($this->sessionPort->findActive() as $candidate) {
            if ($candidate->getUpdatedAt() > $cutoff) {
                continue;
            }
            if ($this->transaction->run(fn (): bool => $this->expireSession($candidate->getId(), $candidate->getVideoId(), $cutoff))) {
                ++$expired;
            }
        }

        return $expired;
    }

    private function expireSession(Uuid $sessionId, Uuid $videoId, DateTimeImmutable $cutoff): bool
    {
        // The lock order of admission and playback control; a client that
        // reports or signs first keeps its session.
        $this->ownership->lockAdmission($videoId);
        $this->ownership->lockSession($sessionId);
        $session = $this->sessionPort->findByUuid($sessionId);
        if ($session === null || !in_array($session->getSessionState(), self::HOLDING_STATES, true) || $session->getUpdatedAt() > $cutoff) {
            return false;
        }
        $runtime = $this->ownership->find($session->getJobId());
        $owned = $runtime !== null && $runtime->sessionId->equals($sessionId);
        if ($owned && $runtime->outcome === 'complete') {
            // A finished presentation costs no worker and is not orphaned output;
            // its session stays so the same client resumes it without a new encode.
            return false;
        }
        if ($owned && in_array($runtime->outcome, self::RUNNABLE_OUTCOMES, true)) {
            // The worker reads the cancellation, stops its encoder and gives up the execution.
            $this->ownership->control($session->getJobId(), $sessionId, new Uuid()->toString(), status: 'cancelled', seek: false);
        }
        $session->markCancelled();
        $this->sessionPort->save($session);
        // The job is released, not cancelled: without a session it is orphaned,
        // which is what job cleanup removes.
        $job = $this->jobPort->findByUuid($session->getJobId());
        if ($job !== null) {
            $job->detachSession();
            $this->jobPort->save($job);
        }

        return true;
    }
}
