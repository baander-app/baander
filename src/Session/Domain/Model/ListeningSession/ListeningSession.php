<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\ListeningSession;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class ListeningSession
{
    private array $pendingEvents = [];

    private function __construct(
        private ListeningSessionState $state,
    ) {
    }

    /**
     * Create a new ListeningSession.
     */
    public static function create(
        Uuid $userId,
        array $queue,
        int $currentTrackIndex,
        float $position,
    ): self {
        if ($currentTrackIndex < 0) {
            throw new InvalidArgumentException('Current track index cannot be negative.');
        }

        if ($position < 0.0) {
            throw new InvalidArgumentException('Position cannot be negative.');
        }

        $now = new DateTimeImmutable();

        $session = new self(new ListeningSessionState(
            id: new Uuid(),
            userId: $userId,
            activeDeviceId: null,
            queue: $queue,
            currentTrackIndex: $currentTrackIndex,
            position: $position,
            playbackState: 'stopped',
            createdAt: $now,
            updatedAt: $now,
            lastUsedAt: null,
        ));

        $session->pendingEvents[] = new \App\Session\Domain\Event\SessionCreated(
            userId: $userId,
            queue: $queue,
            occurredAt: $now,
        );

        return $session;
    }

    /**
     * Reconstitute a ListeningSession from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(ListeningSessionState $state): self
    {
        return new self($state);
    }

    /**
     * Claim this session for a device, transferring the active device.
     * No-op if the device already holds the session.
     */
    public function claim(Uuid $deviceId): void
    {
        if ($this->state->activeDeviceId !== null
            && $this->state->activeDeviceId->equals($deviceId)) {
            return;
        }

        $this->state->activeDeviceId = $deviceId;
        $this->state->updatedAt = new DateTimeImmutable();

        $this->pendingEvents[] = new \App\Session\Domain\Event\SessionClaimed(
            userId: $this->state->userId,
            deviceId: $deviceId,
            occurredAt: new DateTimeImmutable(),
        );
    }

    /**
     * Update playback state: queue, track index, position, and playback state.
     */
    public function updatePlayback(array $queue, int $currentTrackIndex, float $position, string $playbackState): void
    {
        if ($currentTrackIndex < 0) {
            throw new InvalidArgumentException('Current track index cannot be negative.');
        }

        if ($position < 0.0) {
            throw new InvalidArgumentException('Position cannot be negative.');
        }

        $validStates = ['playing', 'paused', 'stopped'];
        if (!in_array($playbackState, $validStates, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid playback state "%s". Must be one of: %s',
                $playbackState,
                implode(', ', $validStates),
            ));
        }

        $this->state->queue = $queue;
        $this->state->currentTrackIndex = $currentTrackIndex;
        $this->state->position = $position;
        $this->state->playbackState = $playbackState;
        $this->state->updatedAt = new DateTimeImmutable();

        $this->pendingEvents[] = new \App\Session\Domain\Event\SessionUpdated(
            userId: $this->state->userId,
            deviceId: $this->state->activeDeviceId,
            queue: $queue,
            occurredAt: new DateTimeImmutable(),
        );
    }

    /**
     * End the listening session.
     */
    public function end(): void
    {
        $this->state->playbackState = 'stopped';
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Mark the session as recently used, updating the lastUsedAt timestamp.
     */
    public function markUsed(): void
    {
        $now = new DateTimeImmutable();
        $this->state->lastUsedAt = $now;
        $this->state->updatedAt = $now;
    }

    /**
     * Drain pending domain events.
     *
     * @return list<object>
     */
    public function drainPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getActiveDeviceId(): ?Uuid
    {
        return $this->state->activeDeviceId;
    }

    public function getQueue(): array
    {
        return $this->state->queue;
    }

    public function getCurrentTrackIndex(): int
    {
        return $this->state->currentTrackIndex;
    }

    public function getPosition(): float
    {
        return $this->state->position;
    }

    public function getPlaybackState(): string
    {
        return $this->state->playbackState;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->state->lastUsedAt;
    }

    public function getState(): ListeningSessionState
    {
        return $this->state;
    }
}
