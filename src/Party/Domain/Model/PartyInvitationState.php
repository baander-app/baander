<?php

declare(strict_types=1);

namespace App\Party\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for the PartyInvitation aggregate root.
 *
 * An invitation is created and deleted, never changed, so every field is read-only.
 * Only the aggregate root and its repository implementation use this class.
 */
final class PartyInvitationState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public readonly Uuid $sessionId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
