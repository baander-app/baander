<?php

declare(strict_types=1);

namespace App\Party\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * The access rule of every party route except join: a caller reaches a session only
 * through a member row in it, and anyone else is answered as for a session that does
 * not exist. The host holds a member row from the session's creation, so the rule
 * covers the host too. Join decides in its handler, which also admits an invited user.
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
