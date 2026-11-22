<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Uuid;

/**
 * The sessions a credential change of one user revoked: the token ids of every access token of
 * the chains it ended, whose WebSocket connections CredentialChangeRevoker::closeConnections()
 * closes once the change has committed.
 */
final readonly class RevokedSessions
{
    /** @param list<string> $accessTokenIds */
    public function __construct(
        public Uuid $userId,
        public array $accessTokenIds,
    ) {
    }
}
