<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use DateTimeImmutable;
use App\Transcode\Domain\Exception\InvalidSegmentIndexException;
use App\Transcode\Domain\Exception\InvalidSessionTransitionException;

final class TranscodeSession
{
    private function __construct(
        private TranscodeSessionState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        Uuid $jobId,
        Uuid $videoId,
        AudioProfile $audioProfile,
        SessionPriority $priority = SessionPriority::Normal,
        array $audioLanguages = [],
    ): self {
        return new self(new TranscodeSessionState(
            id: new Uuid(),
            publicId: new PublicId(),
            userId: $userId,
            jobId: $jobId,
            videoId: $videoId,
            state: SessionState::Pending,
            priority: $priority,
            audioProfile: $audioProfile,
            currentSegmentIndex: 0,
            wallClockOffset: 0.0,
            metrics: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            audioLanguages: $audioLanguages,
        ));
    }

    public static function reconstitute(TranscodeSessionState $state): self
    {
        return new self($state);
    }

    private function transitionTo(SessionState $target): void
    {
        if (!$this->state->state->canTransitionTo($target)) {
            throw InvalidSessionTransitionException::fromState($this->state->state->value, $target->value);
        }

        $this->state->state = $target;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markPreparing(): void
    {
        $this->transitionTo(SessionState::Preparing);
    }

    public function markActive(): void
    {
        $this->transitionTo(SessionState::Active);
    }

    public function markPaused(): void
    {
        $this->transitionTo(SessionState::Paused);
    }

    public function markResumed(): void
    {
        $this->transitionTo(SessionState::Active);
    }

    public function markCompleted(): void
    {
        $this->transitionTo(SessionState::Completed);
    }

    public function markFailed(): void
    {
        if (in_array($this->state->state, [SessionState::Completed, SessionState::Cancelled], true)) {
            return;
        }

        $this->state->state = SessionState::Failed;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        if (in_array($this->state->state, [SessionState::Completed], true)) {
            return;
        }

        $this->state->state = SessionState::Cancelled;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateCurrentSegment(int $index): void
    {
        if ($index < 0) {
            throw InvalidSegmentIndexException::negativeIndex();
        }

        $this->state->currentSegmentIndex = $index;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function updateMetrics(array $metrics): void
    {
        $this->state->metrics = array_merge($this->state->metrics, $metrics);
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateAudioProfile(AudioProfile $profile): void
    {
        $this->state->audioProfile = $profile;
        $this->state->updatedAt = new \DateTimeImmutable();
    }

    public function updateWallClockOffset(float $offset): void
    {
        $this->state->wallClockOffset = $offset;
        $this->state->updatedAt = new \DateTimeImmutable();
    }

    public function getState(): TranscodeSessionState
    {
        return $this->state;
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getUserId(): Uuid { return $this->state->userId; }
    public function getJobId(): Uuid { return $this->state->jobId; }
    public function getVideoId(): Uuid { return $this->state->videoId; }
    public function getSessionState(): SessionState { return $this->state->state; }
    public function getPriority(): SessionPriority { return $this->state->priority; }
    public function getAudioProfile(): AudioProfile { return $this->state->audioProfile; }
    public function getCurrentSegmentIndex(): int { return $this->state->currentSegmentIndex; }
    public function getWallClockOffset(): float { return $this->state->wallClockOffset; }

    /** @return array<string, mixed> */
    public function getMetrics(): array { return $this->state->metrics; }

    public function getCreatedAt(): DateTimeImmutable { return $this->state->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->state->updatedAt; }

    /** @return string[] */
    public function getAudioLanguages(): array { return $this->state->audioLanguages; }
}
