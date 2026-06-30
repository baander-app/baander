<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * Command DTO for revoking an OAuth 2.0 token.
 *
 * Supports revoking access tokens, refresh tokens, and entire token chains.
 */
final readonly class RevokeTokenCommand
{
    public function __construct(
        private string $tokenId,
        private bool $revokeChain = false,
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
}
