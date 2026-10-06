<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateInterval;

/**
 * OAuth 2.0 Authorization Code aggregate root.
 *
 * Represents a short-lived authorization code issued after user consent
 * during the Authorization Code grant flow (with PKCE support).
 */
final class AuthCode
{
    private function __construct(
        private AuthCodeState $state,
    ) {
    }

    /**
     * Create a new authorization code.
     *
     * @param Scope[] $scopes
     */
    public static function create(
        User $user,
        Client $client,
        array $scopes = [],
        ?DateInterval $ttl = null,
    ): self {
        $expiresAt = null;
        if ($ttl !== null) {
            $expiresAt = (new DateTimeImmutable())->add($ttl);
        }

        return new self(new AuthCodeState(
            id: new Uuid(),
            codeId: TokenId::generate(),
            user: $user,
            client: $client,
            scopes: $scopes,
            expiresAt: $expiresAt,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute an AuthCode from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(AuthCodeState $state): self
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

    public function getCodeId(): TokenId
    {
        return $this->state->codeId;
    }

    public function getUser(): User
    {
        return $this->state->user;
    }

    public function getClient(): Client
    {
        return $this->state->client;
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

    public function isRevoked(): bool
    {
        return $this->state->revoked;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): AuthCodeState
    {
        return $this->state;
    }
}
