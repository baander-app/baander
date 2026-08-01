<?php

declare(strict_types=1);

namespace App\Party\Domain\Repository;

use App\Party\Domain\Model\PartyMember;
use App\Shared\Domain\Model\Uuid;

interface PartyMemberRepositoryInterface
{
    public function save(PartyMember $member): void;

    public function findByUuid(Uuid $uuid): ?PartyMember;

    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyMember;

    /**
     * @return PartyMember[]
     */
    public function findBySession(Uuid $sessionId): array;

    public function countBySession(Uuid $sessionId): int;

    public function delete(PartyMember $member): void;
}
