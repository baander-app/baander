<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * DTO representing a successful token issuance response.
 *
 * Follows the OAuth 2.0 token response format per RFC 6749 Section 5.1,
 * with additional fields for refresh token rotation and device code flows.
 */
final readonly class TokenResponseDTO
{
    /**
     * @param string $accessToken The DPoP-bound access token string
     * @param string $tokenType Token type, defaults to "DPoP" for RFC 9449
     * @param int $expiresIn Seconds until the access token expires
     * @param string|null $refreshToken The refresh token string (if applicable)
     * @param string[] $scopes The granted scopes
     * @param string|null $deviceId Device code user code (device_code grant only)
     * @param int $verificationInterval Polling interval for device code flow
     */
    public function __construct(
        private string $accessToken,
        private string $tokenType = 'DPoP',
        private int $expiresIn = 3600,
        private ?string $refreshToken = null,
        private array $scopes = [],
        private ?string $deviceId = null,
        private int $verificationInterval = 5,
    ) {
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getTokenType(): string
    {
        return $this->tokenType;
    }

    public function getExpiresIn(): int
    {
        return $this->expiresIn;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function getVerificationInterval(): int
    {
        return $this->verificationInterval;
    }

    /**
     * Convert to an array suitable for JSON serialization (RFC 6749 format).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
        ];

        if ($this->refreshToken !== null) {
            $data['refresh_token'] = $this->refreshToken;
        }

        if ($this->scopes !== []) {
            $data['scope'] = implode(' ', $this->scopes);
        }

        return $data;
    }
}
