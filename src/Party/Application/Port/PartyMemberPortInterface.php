<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Domain\Model\PartyMember;
use App\Shared\Domain\Model\Uuid;

interface PartyMemberPortInterface
{
    public function addMember(Uuid $userId, Uuid $sessionId): PartyMember;

    public function findByUuid(Uuid $uuid): ?PartyMember;

    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyMember;

    /** @return PartyMember[] */
    public function findBySession(Uuid $sessionId): array;

    public function countBySession(Uuid $sessionId): int;

    public function removeMember(Uuid $userId, Uuid $sessionId): void;

    public function save(PartyMember $member): void;
}
