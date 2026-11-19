<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateInterval;

/**
 * OAuth 2.0 Refresh Token aggregate root.
 *
 * Enables token rotation: each refresh token points to its predecessor
 * and carries a chain ID. Reusing a previous refresh token triggers
 * revocation of the entire chain (replay detection).
 */
final class RefreshToken
{
    private function __construct(
        private RefreshTokenState $state,
    ) {
    }

    /**
     * Issue a new refresh token under its access token's credential epoch.
     *
     * When issued as part of a token rotation, pass the previous refresh token
     * to maintain chain continuity; the chain keeps the absolute expiry it got when
     * it started. For initial issuance, pass null and the chain's maximum lifetime.
     */
    public static function issue(
        AccessToken $accessToken,
        ?ChainId $chainId = null,
        ?DateInterval $ttl = null,
        ?self $previousRefreshToken = null,
        ?DateInterval $maxChainLifetime = null,
    ): self {
        $now = new DateTimeImmutable();
        $expiresAt = $ttl === null ? null : $now->add($ttl);
        $chainExpiresAt = $previousRefreshToken === null
            ? ($maxChainLifetime === null ? null : $now->add($maxChainLifetime))
            : $previousRefreshToken->getChainExpiresAt();

        return new self(new RefreshTokenState(
            id: new Uuid(),
            tokenId: TokenId::generate(),
            accessToken: $accessToken,
            chainId: $chainId,
            previousRefreshToken: $previousRefreshToken,
            expiresAt: $expiresAt,
            usedAt: null,
            createdAt: $now,
            updatedAt: $now,
            chainExpiresAt: $chainExpiresAt,
            credentialEpoch: $accessToken->getCredentialEpoch(),
        ));
    }

    /**
     * Reconstitute a RefreshToken from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(RefreshTokenState $state): self
    {
        return new self($state);
    }

    public function revoke(): void
    {
        if ($this->state->revoked) {
            return;
        }

        $this->state->revoked = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->state->usedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Check whether this token has already been used (rotation detected).
     *
     * A refresh token that has been used is expected to be rotated away.
     * If it appears again after being used, that signals a replay attack.
     */
    public function hasBeenUsed(): bool
    {
        return $this->state->usedAt !== null;
    }

    public function isExpired(): bool
    {
        if ($this->state->expiresAt === null) {
            return false;
        }

        return $this->state->expiresAt < new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    /**
     * The token to hand to the client. Only the request that issued the token knows it; a
     * token read back from storage has only its hash.
     */
    public function getTokenId(): TokenId
    {
        return $this->state->tokenId
            ?? throw new \LogicException('A stored refresh token keeps only its hash.');
    }

    public function getChainExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->chainExpiresAt;
    }

    public function getCredentialEpoch(): int
    {
        return $this->state->credentialEpoch;
    }

    public function getAccessToken(): AccessToken
    {
        return $this->state->accessToken;
    }

    public function getChainId(): ?ChainId
    {
        return $this->state->chainId;
    }

    public function getPreviousRefreshToken(): ?self
    {
        return $this->state->previousRefreshToken;
    }

    public function isRevoked(): bool
    {
        return $this->state->revoked;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->state->usedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): RefreshTokenState
    {
        return $this->state;
    }
}
