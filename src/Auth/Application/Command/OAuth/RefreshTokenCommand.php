<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\PublicId;

/**
 * Command DTO for refreshing an OAuth 2.0 access token.
 *
 * The handler will validate the refresh token, check chain integrity,
 * rotate the token, and return a new token pair.
 */
final readonly class RefreshTokenCommand
{
    public function __construct(
        private string $refreshTokenId,
        private ?string $ipAddress = null,
        private ?string $userAgent = null,
        private ?string $clientFingerprint = null,
        private ?string $dpopJkt = null,
        private ?PublicId $clientId = null,
    ) {
    }

    /**
     * The client the token must have been issued to: the authenticated client at the
     * token endpoint, or the first-party client at the first-party refresh endpoint.
     * Null skips the check.
     */
    public function getClientId(): ?PublicId
    {
        return $this->clientId;
    }

    public function getRefreshTokenId(): string
    {
        return $this->refreshTokenId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getClientFingerprint(): ?string
    {
        return $this->clientFingerprint;
    }

    public function getDpopJkt(): ?string
    {
        return $this->dpopJkt;
    }
}
