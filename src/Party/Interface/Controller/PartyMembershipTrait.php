<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * The access rule every party read applies: a caller sees a session only through a
 * member row in it. The host holds a member row from the session's creation, so the
 * rule covers the host too.
 *
 * @property-read PartyMemberPortInterface $memberPort
 */
trait PartyMembershipTrait
{
    private function belongsTo(Uuid $sessionId, AuthenticatedUserIdentityInterface $user): bool
    {
        return $this->memberPort->findByUserAndSession(Uuid::fromString($user->getId()), $sessionId) !== null;
    }
}
