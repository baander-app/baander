<?php

declare(strict_types=1);

namespace App\Party\Infrastructure;

use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Domain\Model\PartyInvitation;
use App\Party\Domain\Repository\PartyInvitationRepositoryInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class PartyInvitationService implements PartyInvitationPortInterface
{
    public function __construct(
        private PartyInvitationRepositoryInterface $invitationRepository,
    ) {
    }

    public function invite(Uuid $userId, Uuid $sessionId): void
    {
        if ($this->invitationRepository->existsForUserAndSession($userId, $sessionId)) {
            return;
        }

        $this->invitationRepository->save(PartyInvitation::issue($userId, $sessionId));
    }

    public function revoke(Uuid $userId, Uuid $sessionId): bool
    {
        $invitation = $this->invitationRepository->findByUserAndSession($userId, $sessionId);
        if ($invitation === null) {
            return false;
        }

        $this->invitationRepository->delete($invitation);

        return true;
    }

    public function isInvited(Uuid $userId, Uuid $sessionId): bool
    {
        return $this->invitationRepository->existsForUserAndSession($userId, $sessionId);
    }

    public function findBySession(Uuid $sessionId): array
    {
        return $this->invitationRepository->findBySession($sessionId);
    }
}
