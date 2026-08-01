<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * Command DTO for issuing an OAuth 2.0 token.
 *
 * Supports all configured grant types: authorization_code, client_credentials,
 * refresh_token, direct_grant, and urn:ietf:params:oauth:grant-type:device_code.
 */
final readonly class IssueTokenCommand
{
    public function __construct(
        private string $grantType,
        private ?Uuid $clientId = null,
        private ?string $clientSecret = null,
        private ?Uuid $userId = null,
        /** @var string[] */
        private array $scopes = [],
        // Authorization Code + PKCE
        private ?string $code = null,
        private ?string $redirectUri = null,
        private ?string $codeVerifier = null,
        // Direct grant
        private ?string $username = null,
        private ?string $password = null,
        // Device Code grant
        private ?string $deviceCode = null,
        // Token metadata
        private ?string $tokenName = null,
        private ?string $ipAddress = null,
        private ?string $userAgent = null,
        private ?string $clientFingerprint = null,
        private ?string $dpopJkt = null,
    ) {
    }

    public function getGrantType(): string
    {
        return $this->grantType;
    }

    public function getClientId(): ?Uuid
    {
        return $this->clientId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    public function getCodeVerifier(): ?string
    {
        return $this->codeVerifier;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getDeviceCode(): ?string
    {
        return $this->deviceCode;
    }

    public function getTokenName(): ?string
    {
        return $this->tokenName;
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
