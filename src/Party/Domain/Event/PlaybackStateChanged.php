<?php

declare(strict_types=1);

namespace App\Party\Domain\Event;

use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * The host played, paused or sought in a party session. Carries the session's playback
 * state as it stood right after the change, so the party's members can follow it.
 */
final readonly class PlaybackStateChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $sessionId,
        private readonly PublicId $sessionPublicId,
        private readonly PlaybackState $playbackState,
        private readonly float $currentPosition,
        private readonly float $wallClockPosition,
        private readonly DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function of(SyncedPartySession $session): self
    {
        return new self(
            sessionId: $session->getId(),
            sessionPublicId: $session->getPublicId(),
            playbackState: $session->getPlaybackState(),
            currentPosition: $session->getCurrentPosition(),
            wallClockPosition: $session->getWallClockPosition(),
            updatedAt: $session->getUpdatedAt(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['session_id']),
            PublicId::fromString($payload['session_public_id']),
            PlaybackState::from($payload['playback_state']),
            (float) $payload['current_position'],
            (float) $payload['wall_clock_position'],
            new DateTimeImmutable($payload['updated_at']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    /**
     * @return array{session_id: string, session_public_id: string, playback_state: string, current_position: float, wall_clock_position: float, updated_at: string, occurred_at: string}
     */
    public function toPayload(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'session_public_id' => $this->sessionPublicId->toString(),
            'playback_state' => $this->playbackState->value,
            'current_position' => $this->currentPosition,
            'wall_clock_position' => $this->wallClockPosition,
            'updated_at' => $this->updatedAt->format(DateTimeImmutable::ATOM),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'party.playback_state_changed';
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getSessionPublicId(): PublicId
    {
        return $this->sessionPublicId;
    }

    public function getPlaybackState(): PlaybackState
    {
        return $this->playbackState;
    }

    public function getCurrentPosition(): float
    {
        return $this->currentPosition;
    }

    public function getWallClockPosition(): float
    {
        return $this->wallClockPosition;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
