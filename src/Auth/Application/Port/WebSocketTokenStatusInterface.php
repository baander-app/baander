<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

interface WebSocketTokenStatusInterface
{
    /** Fresh expiry only when the token is owned by this enabled user, live and DPoP-bound. */
    public function expiresAt(string $userId, string $accessTokenId): ?\DateTimeImmutable;
}
