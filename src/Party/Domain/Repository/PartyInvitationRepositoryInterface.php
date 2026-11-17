<?php

declare(strict_types=1);

namespace App\Party\Domain\Repository;

use App\Party\Domain\Model\PartyInvitation;
use App\Shared\Domain\Model\Uuid;

interface PartyInvitationRepositoryInterface
{
    /** Stores a new invitation. An invitation never changes, so saving a stored one writes nothing. */
    public function save(PartyInvitation $invitation): void;

    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyInvitation;

    /** Whether the invitation's row exists now, whatever was loaded earlier. */
    public function existsForUserAndSession(Uuid $userId, Uuid $sessionId): bool;

    /**
     * A session's invitations, oldest first: by creation time, then by ID.
     *
     * @return list<PartyInvitation>
     */
    public function findBySession(Uuid $sessionId): array;

    public function delete(PartyInvitation $invitation): void;
}
