<?php

declare(strict_types=1);

namespace App\Party\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * A host's standing permission for one user to join one party.
 *
 * Joining and leaving do not use it up: it lasts until it is revoked or its party or user is deleted.
 */
final class PartyInvitation
{
    private function __construct(
        private PartyInvitationState $state,
    ) {
    }

    /**
     * Issue a new invitation for a user to a party session.
     */
    public static function issue(Uuid $userId, Uuid $sessionId): self
    {
        return new self(new PartyInvitationState(
            id: new Uuid(),
            userId: $userId,
            sessionId: $sessionId,
            createdAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a PartyInvitation from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(PartyInvitationState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getSessionId(): Uuid
    {
        return $this->state->sessionId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getState(): PartyInvitationState
    {
        return $this->state;
    }
}
