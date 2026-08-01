<?php

declare(strict_types=1);

namespace App\Party\Domain\Model;

use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class PartyMember
{
    private function __construct(
        private PartyMemberState $state,
    ) {
    }

    /**
     * Create a new PartyMember aggregate root.
     */
    public static function create(
        Uuid $userId,
        Uuid $sessionId,
        MemberRole $role = MemberRole::Member,
    ): self {
        return new self(new PartyMemberState(
            id: new Uuid(),
            publicId: new PublicId(),
            userId: $userId,
            sessionId: $sessionId,
            joinedAt: new DateTimeImmutable(),
            role: $role,
            audioProfileId: null,
            subtitleTrackId: null,
            lastSyncPosition: 0.0,
            lastSyncAt: null,
            jitterCompensation: 0.0,
            isConnected: true,
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a PartyMember from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(PartyMemberState $state): self
    {
        return new self($state);
    }

    /**
     * Promote this member to host role.
     */
    public function promoteToHost(): void
    {
        if ($this->state->role === MemberRole::Host) {
            return;
        }

        $this->state->role = MemberRole::Host;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Demote this member to regular member role.
     */
    public function demoteToMember(): void
    {
        if ($this->state->role === MemberRole::Member) {
            return;
        }

        $this->state->role = MemberRole::Member;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update the member's sync position and jitter compensation.
     */
    public function updateSyncPosition(float $position, float $jitter): void
    {
        $this->state->lastSyncPosition = $position;
        $this->state->jitterCompensation = $jitter;
        $this->state->lastSyncAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Set the member's preferred audio profile.
     */
    public function setAudioProfile(?string $profileId): void
    {
        $this->state->audioProfileId = $profileId;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Set the member's preferred subtitle track.
     */
    public function setSubtitleTrack(?string $trackId): void
    {
        $this->state->subtitleTrackId = $trackId;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Mark the member as disconnected.
     */
    public function disconnect(): void
    {
        $this->state->isConnected = false;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Mark the member as reconnected.
     */
    public function reconnect(): void
    {
        $this->state->isConnected = true;
        $this->state->updatedAt = new DateTimeImmutable();
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

    public function getSessionId(): Uuid
    {
        return $this->state->sessionId;
    }

    public function getJoinedAt(): DateTimeImmutable
    {
        return $this->state->joinedAt;
    }

    public function getRole(): MemberRole
    {
        return $this->state->role;
    }

    public function getAudioProfileId(): ?string
    {
        return $this->state->audioProfileId;
    }

    public function getSubtitleTrackId(): ?string
    {
        return $this->state->subtitleTrackId;
    }

    public function getLastSyncPosition(): float
    {
        return $this->state->lastSyncPosition;
    }

    public function getLastSyncAt(): ?DateTimeImmutable
    {
        return $this->state->lastSyncAt;
    }

    public function getJitterCompensation(): float
    {
        return $this->state->jitterCompensation;
    }

    public function isConnected(): bool
    {
        return $this->state->isConnected;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): PartyMemberState
    {
        return $this->state;
    }
}
