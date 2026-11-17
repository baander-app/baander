<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * The invited user travels as the public ID the caller named: the handler resolves it only
 * after it knows the caller is the host.
 */
final readonly class InvitePartyMemberCommand
{
    public function __construct(
        private Uuid $sessionId,
        private Uuid $hostUserId,
        private PublicId $userPublicId,
    ) {
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getHostUserId(): Uuid { return $this->hostUserId; }
    public function getUserPublicId(): PublicId { return $this->userPublicId; }
}
