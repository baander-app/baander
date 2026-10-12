<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * Command DTO for revoking an OAuth 2.0 token.
 *
 * Supports revoking access tokens, refresh tokens, and entire token chains. With
 * $ownerUserId set, only a token issued to that user is revoked; any other token
 * is left alone and the handler completes as for an unknown token, so the
 * revocation endpoint answers both alike (RFC 7009 section 2.2).
 */
final readonly class RevokeTokenCommand
{
    public function __construct(
        private string $tokenId,
        private bool $revokeChain = false,
        private ?string $ownerUserId = null,
    ) {
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function shouldRevokeChain(): bool
    {
        return $this->revokeChain;
    }

    /** The user the token must belong to, or null when any token may be revoked. */
    public function getOwnerUserId(): ?string
    {
        return $this->ownerUserId;
    }
}
