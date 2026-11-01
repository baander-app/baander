<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\ListeningSession;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use DateTimeImmutable;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use InvalidArgumentException;

final class ListeningSession
{
    /**
     * @var list<\App\Shared\Domain\Event\AbstractDomainEvent>
     */
    private array $pendingEvents = [];

    private function __construct(
        private ListeningSessionState $state,
    ) {}

    /**
     * Create a new ListeningSession.
     * @param array<array-key, mixed> $queue
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
            publicId: new PublicId(),
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

    public function releaseDevice(Uuid $deviceId): void
    {
        if ($this->state->activeDeviceId?->equals($deviceId)) {
            $this->state->activeDeviceId = null;
            $this->state->updatedAt = new DateTimeImmutable();
        }
    }

    /**
     * Update playback state: queue, track index, position, and playback state.
     * @param array<array-key, mixed> $queue
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

    /** @param list<string>|null $queue */
    public function control(
        string $action,
        ?PlaybackMedia $media,
        ?array $queue,
        ?int $index,
        ?float $position,
        ?PlaybackState $desired,
        ?float $volume = null,
    ): void {
        if (!in_array($action, ['load', 'play', 'pause', 'seek', 'stop', 'volume'], true)) {
            throw new InvalidArgumentException('Invalid playback action.');
        }
        if ($action === 'volume') {
            if ($volume === null || !is_finite($volume) || $volume < 0 || $volume > 1) {
                throw new InvalidArgumentException('Volume must be finite and between zero and one.');
            }
            if ($media !== null || $queue !== null || $index !== null || $position !== null || $desired !== null) {
                throw new InvalidArgumentException('Volume commands cannot change media, queue, position or playback state.');
            }
            $this->advanceRevision();
            return;
        }
        if ($volume !== null) {
            throw new InvalidArgumentException('Volume requires the volume action.');
        }
        if ($action !== 'load' && $desired !== null) {
            throw new InvalidArgumentException('Only loading media may supply a desired playback state.');
        }
        if ($action === 'stop'
            && ($media !== null || $queue !== null || $index !== null || $position !== null)
        ) {
            throw new InvalidArgumentException('Stop retains the current media, queue and cursor.');
        }
        $nextQueue = $queue ?? $this->state->queue;
        $nextIndex = $index ?? $this->state->currentTrackIndex;
        if ($nextIndex < 0 || ($nextQueue === [] ? $nextIndex !== 0 : $nextIndex >= count($nextQueue))) {
            throw new InvalidArgumentException('The queue cursor must refer to an existing queue item.');
        }
        foreach ($nextQueue as $id) {
            PublicId::fromString($id);
        }
        if ($position !== null && (!is_finite($position) || $position < 0)) {
            throw new InvalidArgumentException('Position must be finite and nonnegative.');
        }
        $this->state->queue = $nextQueue;
        $this->state->currentTrackIndex = $nextIndex;
        if ($media !== null) {
            $this->state->media = $media;
        }
        if ($position !== null) {
            $this->state->position = $position;
        }
        $this->state->desiredState = $desired ?? match ($action) {
            'play' => PlaybackState::Playing,
            'pause' => PlaybackState::Paused,
            'stop' => PlaybackState::Stopped,
            default => $this->state->desiredState,
        };
        $this->advanceRevision();
    }

    public function selectDestination(Uuid $destinationId): void
    {
        $this->state->selectedDestinationId = $destinationId;
        $this->advanceRevision();
    }

    public function confirmQuiescence(float $position): void
    {
        if (!is_finite($position) || $position < 0) {
            throw new InvalidArgumentException('Invalid source cursor.');
        }
        $this->state->position = $position;
        $this->state->desiredState = PlaybackState::Paused;
        $this->state->observedState = ObservedPlaybackState::Paused;
    }

    public function commitDestination(Uuid $incarnationId, Uuid $destinationId, PlaybackState $desired, float $position): void
    {
        $this->state->activeIncarnationId = $incarnationId;
        $this->state->selectedDestinationId = $destinationId;
        $this->state->desiredState = $desired;
        $this->state->observedState = ObservedPlaybackState::from($desired->value);
        $this->state->position = $position;
    }

    public function retainUncertainRunner(): void
    {
        $this->state->desiredState = PlaybackState::Paused;
        $this->state->observedState = ObservedPlaybackState::Unknown;
    }

    public function restoreSelection(?Uuid $destinationId): void
    {
        $this->state->selectedDestinationId = $destinationId;
    }

    public function observePlayback(ObservedPlaybackState $observed, float $position): void
    {
        if (!is_finite($position) || $position < 0) {
            throw new InvalidArgumentException('Invalid observed cursor.');
        }
        $this->state->observedState = $observed;
        $this->state->position = $position;
    }

    public function confirmControl(bool $rejected, ?float $position): void
    {
        $this->observePlayback($rejected ? ObservedPlaybackState::Unknown : ObservedPlaybackState::from($this->state->desiredState->value), $position ?? $this->state->position);
    }

    public function advanceRevision(): void
    {
        ++$this->state->revision;
        $this->state->playbackState = $this->state->desiredState->value;
        $this->markUsed();
    }

    public function revision(): int
    {
        return $this->state->revision;
    }
    public function media(): ?PlaybackMedia
    {
        return $this->state->media;
    }
    public function desiredState(): PlaybackState
    {
        return $this->state->desiredState;
    }
    public function observedState(): ObservedPlaybackState
    {
        return $this->state->observedState;
    }
    public function selectedDestinationId(): ?Uuid
    {
        return $this->state->selectedDestinationId;
    }
    public function activeIncarnationId(): ?Uuid
    {
        return $this->state->activeIncarnationId;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getActiveDeviceId(): ?Uuid
    {
        return $this->state->activeDeviceId;
    }

    /**
     * @return array<array-key, mixed>
     */
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
