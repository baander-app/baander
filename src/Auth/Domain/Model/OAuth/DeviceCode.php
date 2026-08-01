<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateInterval;
use RuntimeException;

/**
 * OAuth 2.0 Device Authorization Grant aggregate root (RFC 8628).
 *
 * Represents a device code issued when a device requests authorization
 * on behalf of a user. The user must approve the request by entering
 * the user code at the verification URI.
 */
final class DeviceCode
{
    private function __construct(
        private DeviceCodeState $state,
    ) {
    }

    /**
     * Create a new device code authorization request.
     *
     * @param Scope[] $scopes
     */
    public static function create(
        Client $client,
        string $userCode,
        string $verificationUri,
        ?string $verificationUriComplete = null,
        array $scopes = [],
        ?DateInterval $ttl = null,
        int $interval = 5,
    ): self {
        $expiresAt = null;
        if ($ttl !== null) {
            $expiresAt = (new DateTimeImmutable())->add($ttl);
        }

        return new self(new DeviceCodeState(
            id: new Uuid(),
            deviceCode: TokenId::generate(),
            userCode: $userCode,
            user: null,
            client: $client,
            scopes: $scopes,
            verificationUri: $verificationUri,
            verificationUriComplete: $verificationUriComplete,
            expiresAt: $expiresAt,
            interval: $interval,
            lastPolledAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a DeviceCode from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(DeviceCodeState $state): self
    {
        return new self($state);
    }

    /**
     * Approve the device code request and bind it to a user.
     */
    public function approve(User $user): void
    {
        if ($this->state->approved) {
            return;
        }

        if ($this->state->denied) {
            throw new RuntimeException('Cannot approve a device code that has already been denied.');
        }

        $this->state->user = $user;
        $this->state->approved = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Deny the device code request.
     */
    public function deny(): void
    {
        if ($this->state->denied) {
            return;
        }

        if ($this->state->approved) {
            throw new RuntimeException('Cannot deny a device code that has already been approved.');
        }

        $this->state->denied = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markPolled(): void
    {
        $this->state->lastPolledAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Consume the device code, marking it as used to issue tokens.
     *
     * This makes token issuance idempotent: once consumed, the device code
     * cannot issue tokens again.
     *
     * @throws RuntimeException if the device code has already been consumed
     * @throws RuntimeException if the device code has not been approved
     */
    public function consume(): void
    {
        if ($this->state->consumedAt !== null) {
            throw new RuntimeException('Device code has already been consumed.');
        }

        if (!$this->state->approved) {
            throw new RuntimeException('Cannot consume a device code that has not been approved.');
        }

        $this->state->consumedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isConsumed(): bool
    {
        return $this->state->consumedAt !== null;
    }

    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->state->consumedAt;
    }

    public function isExpired(): bool
    {
        if ($this->state->expiresAt === null) {
            return false;
        }

        return $this->state->expiresAt < new DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return !$this->state->approved && !$this->state->denied;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getDeviceCode(): TokenId
    {
        return $this->state->deviceCode;
    }

    public function getUserCode(): string
    {
        return $this->state->userCode;
    }

    public function getUser(): ?User
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

    public function getVerificationUri(): string
    {
        return $this->state->verificationUri;
    }

    public function getVerificationUriComplete(): ?string
    {
        return $this->state->verificationUriComplete;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getInterval(): int
    {
        return $this->state->interval;
    }

    public function getLastPolledAt(): ?DateTimeImmutable
    {
        return $this->state->lastPolledAt;
    }

    public function isApproved(): bool
    {
        return $this->state->approved;
    }

    public function isDenied(): bool
    {
        return $this->state->denied;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): DeviceCodeState
    {
        return $this->state;
    }
}
