<?php

declare(strict_types=1);

namespace App\Party\Domain\Repository;

use App\Party\Domain\Model\PartyMember;
use App\Shared\Domain\Model\Uuid;

interface PartyMemberRepositoryInterface
{
    public function save(PartyMember $member): void;

    public function findByUuid(Uuid $uuid): ?PartyMember;

    /**
     * The user's member row in the session as it is stored now, also when the member was loaded
     * earlier through the same unit of work.
     */
    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyMember;

    /**
     * The session's member rows as they are stored now, also when a member was loaded earlier
     * through the same unit of work.
     *
     * @return PartyMember[]
     */
    public function findBySession(Uuid $sessionId): array;

    public function countBySession(Uuid $sessionId): int;

    public function delete(PartyMember $member): void;
}
