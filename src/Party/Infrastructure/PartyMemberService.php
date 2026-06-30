<?php

declare(strict_types=1);

namespace App\Party\Infrastructure;

use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Repository\PartyMemberRepositoryInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class PartyMemberService implements PartyMemberPortInterface
{
    public function __construct(
        private PartyMemberRepositoryInterface $memberRepository,
    ) {
    }

    public function addMember(Uuid $userId, Uuid $sessionId): PartyMember
    {
        $member = PartyMember::create($userId, $sessionId);
        $this->memberRepository->save($member);

        return $member;
    }

    public function findByUuid(Uuid $uuid): ?PartyMember
    {
        return $this->memberRepository->findByUuid($uuid);
    }

    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyMember
    {
        return $this->memberRepository->findByUserAndSession($userId, $sessionId);
    }

    public function findBySession(Uuid $sessionId): array
    {
        return $this->memberRepository->findBySession($sessionId);
    }

    public function countBySession(Uuid $sessionId): int
    {
        return $this->memberRepository->countBySession($sessionId);
    }

    public function removeMember(Uuid $userId, Uuid $sessionId): void
    {
        $member = $this->memberRepository->findByUserAndSession($userId, $sessionId);
        if ($member !== null) {
            $member->disconnect();
            $this->memberRepository->delete($member);
        }
    }

    public function save(PartyMember $member): void
    {
        $this->memberRepository->save($member);
    }
}
