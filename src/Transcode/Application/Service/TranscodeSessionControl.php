<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Domain\Exception\SessionNotFoundException;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Repository\TranscodeSessionRepositoryInterface;
use App\Transcode\Domain\ValueObject\SessionState;

/**
 * Changes one transcode session together with the execution it holds, each change in a transaction of
 * its own. Playback control changes a client's tiers together; this changes only the session named.
 *
 * Lock order of admission and playback control: the video's admission row, then the session's
 * execution row, after which the session is read again. A worker still acting on the execution reads
 * the new status; a finished execution is left complete.
 */
final readonly class TranscodeSessionControl
{
    private const array HOLDING_STATES = [SessionState::Pending, SessionState::Preparing, SessionState::Active, SessionState::Paused];

    /** Execution outcomes a worker still acts on. */
    private const array RUNNABLE_OUTCOMES = ['pending', 'accepted', 'ready'];

    public function __construct(
        private TranscodeSessionRepositoryInterface $sessions,
        private TranscodeExecutionOwnershipPort $ownership,
        private TranscodeJobPortInterface $jobs,
        private TransactionPortInterface $transaction,
    ) {}

    /**
     * Ends a session that still holds its job and releases the job without cancelling it: without a
     * session the job is orphaned, which job cleanup removes.
     *
     * @param (\Closure(TranscodeSession, bool): bool)|null $stays whether the session, read again under
     *        its locks, stays, given whether its execution has finished
     * @return bool whether the session ended
     */
    public function end(TranscodeSession $session, ?\Closure $stays = null): bool
    {
        return $this->transaction->run(function () use ($session, $stays): bool {
            [$current, $execution] = $this->lock($session);
            if ($current === null || !in_array($current->getSessionState(), self::HOLDING_STATES, true)
                || ($stays !== null && $stays($current, $execution?->outcome === 'complete'))
            ) {
                return false;
            }
            $this->control($current, $execution, 'cancelled');
            $current->markCancelled();
            $this->sessions->save($current);
            $job = $this->jobs->findByUuid($current->getJobId());
            if ($job !== null) {
                $job->detachSession();
                $this->jobs->save($job);
            }

            return true;
        });
    }

    /** Pauses a playing session; the worker holds its execution at the lead throttle. */
    public function pause(TranscodeSession $session): void
    {
        $this->transaction->run(function () use ($session): void {
            [$current, $execution] = $this->lock($session);
            if ($current === null) {
                throw SessionNotFoundException::forId($session->getId());
            }
            $current->markPaused();
            $this->control($current, $execution, 'paused');
            $this->sessions->save($current);
        });
    }

    /** Resumes a paused session and its execution. */
    public function resume(TranscodeSession $session): void
    {
        $this->transaction->run(function () use ($session): void {
            [$current, $execution] = $this->lock($session);
            if ($current === null) {
                throw SessionNotFoundException::forId($session->getId());
            }
            $current->markResumed();
            $this->control($current, $execution, 'running');
            $this->sessions->save($current);
        });
    }

    /** @return array{?TranscodeSession, ?TranscodeRuntimeSnapshot} the session read again under its locks, and the execution it holds */
    private function lock(TranscodeSession $session): array
    {
        // A client that reports or signs before the locks keeps what it reported.
        $this->ownership->lockAdmission($session->getVideoId());
        $this->ownership->lockSession($session->getId());
        $current = $this->sessions->findByUuid($session->getId());
        $runtime = $current === null ? null : $this->ownership->find($current->getJobId());

        return [$current, $runtime !== null && $runtime->sessionId->equals($session->getId()) ? $runtime : null];
    }

    private function control(TranscodeSession $session, ?TranscodeRuntimeSnapshot $execution, string $status): void
    {
        if ($execution !== null && in_array($execution->outcome, self::RUNNABLE_OUTCOMES, true)) {
            $this->ownership->control($session->getJobId(), $session->getId(), new Uuid()->toString(), status: $status, seek: false);
        }
    }
}
