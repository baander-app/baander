<?php

declare(strict_types=1);

namespace App\Party\Domain\Model;

use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class SyncedPartySession
{
    private function __construct(
        private SyncedPartySessionState $state,
    ) {
    }

    /**
     * Create a new SyncedPartySession aggregate root.
     */
    public static function create(
        Uuid $hostUserId,
        Uuid $videoId,
        Uuid $transcodeJobId,
        int $maxMembers = 10,
    ): self {
        if ($maxMembers < 2) {
            throw new InvalidArgumentException('Max members must be at least 2.');
        }

        return new self(new SyncedPartySessionState(
            id: new Uuid(),
            publicId: new PublicId(),
            hostUserId: $hostUserId,
            videoId: $videoId,
            transcodeJobId: $transcodeJobId,
            maxMembers: $maxMembers,
            playbackState: PlaybackState::Stopped,
            wallClockPosition: 0.0,
            playbackStartedAt: null,
            pausedAtPosition: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a SyncedPartySession from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(SyncedPartySessionState $state): self
    {
        return new self($state);
    }

    /**
     * Start or resume playback at the given position.
     */
    public function startPlayback(?float $position = null): void
    {
        $startPosition = $position ?? $this->state->pausedAtPosition ?? $this->state->wallClockPosition;

        $this->state->wallClockPosition = $startPosition;
        $this->state->playbackState = PlaybackState::Playing;
        $this->state->playbackStartedAt = new DateTimeImmutable();
        $this->state->pausedAtPosition = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Pause playback and capture the current position.
     */
    public function pausePlayback(): void
    {
        if ($this->state->playbackState === PlaybackState::Paused) {
            return;
        }

        if ($this->state->playbackState !== PlaybackState::Playing) {
            throw new RuntimeException('Cannot pause playback: session is not currently playing.');
        }

        $this->state->pausedAtPosition = $this->getCurrentPosition();
        $this->state->playbackState = PlaybackState::Paused;
        $this->state->playbackStartedAt = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Seek to a specific position in the video.
     */
    public function seekTo(float $position): void
    {
        if ($position < 0.0) {
            throw new InvalidArgumentException('Seek position cannot be negative.');
        }

        $this->state->wallClockPosition = $position;

        if ($this->state->playbackState === PlaybackState::Playing) {
            $this->state->playbackStartedAt = new DateTimeImmutable();
        } else {
            $this->state->pausedAtPosition = $position;
        }

        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Synchronize playback based on client position and latency.
     *
     * Returns the server-adjusted position the client should seek to.
     */
    public function syncPlayback(float $clientPosition, float $clientLatency): float
    {
        if ($this->state->playbackState !== PlaybackState::Playing) {
            return $this->state->wallClockPosition;
        }

        $serverPosition = $this->getCurrentPosition();
        $drift = abs($serverPosition - $clientPosition);

        // If drift is within acceptable tolerance (latency + 1s buffer), return server position
        $tolerance = $clientLatency + 1.0;
        if ($drift <= $tolerance) {
            return $serverPosition;
        }

        // Client is too far out of sync; return the server position for correction
        return $serverPosition;
    }

    /**
     * End the party session.
     */
    public function endSession(): void
    {
        $this->state->playbackState = PlaybackState::Stopped;
        $this->state->isActive = false;
        $this->state->playbackStartedAt = null;
        $this->state->pausedAtPosition = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Transfer host role to another user.
     */
    public function transferHost(Uuid $newHostUserId): void
    {
        $this->state->hostUserId = $newHostUserId;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Compute the current playback position based on elapsed time since playback started.
     */
    public function getCurrentPosition(): float
    {
        if ($this->state->playbackState !== PlaybackState::Playing || $this->state->playbackStartedAt === null) {
            return $this->state->pausedAtPosition ?? $this->state->wallClockPosition;
        }

        $elapsed = (new DateTimeImmutable())->getTimestamp() - $this->state->playbackStartedAt->getTimestamp();

        return $this->state->wallClockPosition + (float) $elapsed;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getHostUserId(): Uuid
    {
        return $this->state->hostUserId;
    }

    public function getVideoId(): Uuid
    {
        return $this->state->videoId;
    }

    public function getTranscodeJobId(): Uuid
    {
        return $this->state->transcodeJobId;
    }

    public function getMaxMembers(): int
    {
        return $this->state->maxMembers;
    }

    public function getPlaybackState(): PlaybackState
    {
        return $this->state->playbackState;
    }

    public function getWallClockPosition(): float
    {
        return $this->state->wallClockPosition;
    }

    public function getPlaybackStartedAt(): ?DateTimeImmutable
    {
        return $this->state->playbackStartedAt;
    }

    public function getPausedAtPosition(): ?float
    {
        return $this->state->pausedAtPosition;
    }

    public function isActive(): bool
    {
        return $this->state->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): SyncedPartySessionState
    {
        return $this->state;
    }
}
