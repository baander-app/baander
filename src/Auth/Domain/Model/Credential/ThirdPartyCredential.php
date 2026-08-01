<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\Credential;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class ThirdPartyCredential
{
    private function __construct(
        private ThirdPartyCredentialState $state,
    ) {
    }

    /**
     * Create a new third-party credential.
     */
    public static function create(
        Uuid $userId,
        string $provider,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        ?DateTimeImmutable $expiresAt = null,
        array $metadata = [],
    ): self {
        if (trim($provider) === '') {
            throw new InvalidArgumentException('Provider cannot be empty.');
        }

        if ($expiresAt !== null && $accessToken === null) {
            throw new InvalidArgumentException('Access token is required when expires_at is set.');
        }

        return new self(new ThirdPartyCredentialState(
            id: new Uuid(),
            userId: $userId,
            provider: $provider,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            metadata: $metadata,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a ThirdPartyCredential from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(ThirdPartyCredentialState $state): self
    {
        return new self($state);
    }

    /**
     * Update the access and refresh tokens.
     */
    public function updateTokens(
        ?string $accessToken,
        ?string $refreshToken,
        ?DateTimeImmutable $expiresAt,
    ): void {
        $this->state->accessToken = $accessToken;
        $this->state->refreshToken = $refreshToken;
        $this->state->expiresAt = $expiresAt;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Revoke the credentials.
     */
    public function revoke(): void
    {
        $this->state->accessToken = null;
        $this->state->refreshToken = null;
        $this->state->expiresAt = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Check if the credential has expired.
     */
    public function isExpired(): bool
    {
        return $this->state->expiresAt !== null && $this->state->expiresAt < new DateTimeImmutable();
    }

    /**
     * Check if the credential has a valid access token.
     */
    public function hasValidAccessToken(): bool
    {
        return $this->state->accessToken !== null && !$this->isExpired();
    }

    /**
     * Add or update metadata.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $this->state->metadata[$key] = $value;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Get metadata value by key.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->state->metadata[$key] ?? $default;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getProvider(): string
    {
        return $this->state->provider;
    }

    public function getAccessToken(): ?string
    {
        return $this->state->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->state->refreshToken;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getMetadata(): array
    {
        return $this->state->metadata;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): ThirdPartyCredentialState
    {
        return $this->state;
    }
}
