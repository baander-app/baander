<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Exception\SessionNotFoundException;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionState;

/** Applies one playback intent to the current owned adaptive tiers in one transaction. */
final readonly class TranscodePlaybackControl
{
    public function __construct(
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
        private TranscodeJobPortInterface $jobPort,
    ) {}

    public function apply(
        Uuid $sessionId,
        string $requestId,
        ?string $status = null,
        ?AudioProfile $audioProfile = null,
        ?float $position = null,
        bool $seek = false,
        bool $progress = false,
        ?int $expectedGeneration = null,
        ?int $expectedRevision = null,
        bool $missingIsNoop = false,
    ): void {
        $this->transaction->run(function () use ($sessionId, $requestId, $status, $audioProfile, $position, $seek, $progress, $expectedGeneration, $expectedRevision, $missingIsNoop): void {
            $group = $this->lockGroup($sessionId, $missingIsNoop);
            $source = $group[$sessionId->toString()] ?? null;
            if ($source === null) {
                return;
            }
            $previous = $this->ownership->find($source->getJobId());
            if ($previous === null || !$previous->sessionId->equals($sessionId)
                || ($seek && $expectedGeneration !== null && $previous->generation !== $expectedGeneration)
                || ($progress && ($previous->generation !== $expectedGeneration
                    || $previous->desiredRevision !== $expectedRevision || $previous->desired->status !== 'running'))
            ) {
                return;
            }
            $canonical = $this->ownership->control($source->getJobId(), $sessionId, $requestId, $status, $audioProfile, $position, seek: $seek);
            $this->project($source, $previous, $canonical);
            if (in_array($canonical->outcome, ['failed', 'refused'], true)) {
                return;
            }
            foreach ($group as $sibling) {
                if ($sibling->getId()->equals($sessionId)) {
                    continue;
                }
                $before = $this->ownership->find($sibling->getJobId());
                if ($before === null || !$before->sessionId->equals($sibling->getId())) {
                    continue;
                }
                // A replay returns the current source intent, so it cannot broadcast an older command.
                $runtime = $this->ownership->control(
                    $sibling->getJobId(),
                    $sibling->getId(),
                    $requestId,
                    status: $status !== null ? $canonical->desired->status : null,
                    audioProfile: $audioProfile !== null ? $canonical->desired->audioProfile : null,
                    position: $position !== null ? $canonical->desired->position : null,
                    seek: $seek,
                );
                $this->project($sibling, $before, $runtime);
            }
        });
    }

    /** @return array<string, TranscodeSession> */
    private function lockGroup(Uuid $sessionId, bool $missingIsNoop): array
    {
        $source = $this->sessionPort->findByUuid($sessionId);
        if ($source === null) {
            if (!$missingIsNoop) {
                throw SessionNotFoundException::forId($sessionId);
            }
            return [];
        }
        $this->ownership->lockAdmission($source->getVideoId());
        $source = $this->sessionPort->findByUuid($sessionId);
        if ($source === null) {
            if (!$missingIsNoop) {
                throw SessionNotFoundException::forId($sessionId);
            }
            return [];
        }
        $candidates = [$source, ...$this->sessionPort->findActiveByUserAndVideo($source->getUserId(), $source->getVideoId())];
        $group = [];
        foreach ($candidates as $session) {
            if (!$session->getUserId()->equals($source->getUserId()) || !$session->getVideoId()->equals($source->getVideoId())) {
                continue;
            }
            $job = $this->jobPort->findByUuid($session->getJobId());
            $runtime = $this->ownership->find($session->getJobId());
            if ($job === null || $job->getOwnerUserId()?->equals($source->getUserId()) !== true
                || $runtime === null || !$runtime->sessionId->equals($session->getId())) {
                continue;
            }
            $group[$session->getId()->toString()] = $session;
        }
        if (!isset($group[$sessionId->toString()])) {
            return [];
        }
        ksort($group, SORT_STRING);
        foreach ($group as $session) {
            $this->ownership->lockSession($session->getId());
        }
        foreach ($group as $id => $session) {
            $current = $this->sessionPort->findByUuid($session->getId());
            if ($current === null) {
                unset($group[$id]);
            } else {
                $group[$id] = $current;
            }
        }
        return $group;
    }

    private function project(TranscodeSession $session, TranscodeRuntimeSnapshot $previous, TranscodeRuntimeSnapshot $runtime): void
    {
        if (in_array($runtime->outcome, ['failed', 'refused'], true)) {
            return;
        }
        if ($runtime->desired->status === 'cancelled') {
            $session->markCancelled();
        } elseif ($runtime->desired->status === 'paused' && $session->getSessionState() === SessionState::Active) {
            $session->markPaused();
        } elseif ($runtime->desired->status === 'running' && $session->getSessionState() === SessionState::Paused) {
            $session->markResumed();
        }
        $session->updateAudioProfile($runtime->desired->audioProfile);
        $session->updateWallClockOffset($runtime->desired->position);
        $this->sessionPort->save($session);
        if ($previous->desired->status !== 'cancelled' && $runtime->desired->status === 'cancelled'
            && !in_array($previous->outcome, ['failed', 'refused'], true)
        ) {
            $job = $this->jobPort->findByUuid($session->getJobId());
            if ($job !== null) {
                if ($job->detachSession()) {
                    $job->markCancelled();
                }
                $this->jobPort->save($job);
            }
        }
    }
}
