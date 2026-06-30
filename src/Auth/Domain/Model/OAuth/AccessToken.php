<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateInterval;

/**
 * OAuth 2.0 Access Token aggregate root.
 *
 * Represents a bearer token issued to a client for authenticating API requests.
 * Each access token is associated with a user (or no user for client credentials),
 * a client, and a set of scopes.
 */
final class AccessToken
{
    private function __construct(
        private AccessTokenState $state,
    ) {
    }

    /**
     * Issue a new access token.
     *
     * @param Scope[] $scopes
     */
    public static function issue(
        Client $client,
        ?User $user = null,
        array $scopes = [],
        ?string $name = null,
        ?DateInterval $ttl = null,
        ?ChainId $chainId = null,
    ): self {
        $expiresAt = null;
        if ($ttl !== null) {
            $expiresAt = (new DateTimeImmutable())->add($ttl);
        }

        return new self(new AccessTokenState(
            id: new Uuid(),
            tokenId: TokenId::generate(),
            user: $user,
            client: $client,
            name: $name,
            scopes: $scopes,
            chainId: $chainId,
            expiresAt: $expiresAt,
            lastRefreshedAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute an AccessToken from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(AccessTokenState $state): self
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

    public function markRefreshed(): void
    {
        $this->state->lastRefreshedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
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

    public function getTokenId(): TokenId
    {
        return $this->state->tokenId;
    }

    public function getUser(): ?User
    {
        return $this->state->user;
    }

    public function getClient(): Client
    {
        return $this->state->client;
    }

    public function getName(): ?string
    {
        return $this->state->name;
    }

    /**
     * @return Scope[]
     */
    public function getScopes(): array
    {
        return $this->state->scopes;
    }

    /**
     * @return string[]
     */
    public function getScopeIdentifiers(): array
    {
        return array_map(fn (Scope $scope) => $scope->toString(), $this->state->scopes);
    }

    public function getChainId(): ?ChainId
    {
        return $this->state->chainId;
    }

    public function isRevoked(): bool
    {
        return $this->state->revoked;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getLastRefreshedAt(): ?DateTimeImmutable
    {
        return $this->state->lastRefreshedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): AccessTokenState
    {
        return $this->state;
    }
}
