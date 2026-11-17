<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Domain\Model\PartyInvitation;
use App\Shared\Domain\Model\Uuid;

/**
 * Which users a party session's host has invited to it.
 *
 * `invite` checks for the invitation and then inserts it, so the caller serialises the writes
 * of one session by holding that session's row lock; two unserialised invitations of one user
 * would collide on the unique index.
 */
interface PartyInvitationPortInterface
{
    /** Invites the user unless they are invited already; a repeated call keeps the first invitation and its creation time. */
    public function invite(Uuid $userId, Uuid $sessionId): void;

    /** Deletes the user's invitation. True when one was deleted. */
    public function revoke(Uuid $userId, Uuid $sessionId): bool;

    /** Whether the user's invitation is stored now, whatever was loaded earlier. */
    public function isInvited(Uuid $userId, Uuid $sessionId): bool;

    /**
     * The session's invitations, oldest first: by creation time, then by ID.
     *
     * @return list<PartyInvitation>
     */
    public function findBySession(Uuid $sessionId): array;
}
