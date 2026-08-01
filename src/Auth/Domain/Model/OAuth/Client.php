<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * OAuth 2.0 Client aggregate root.
 *
 * Represents an OAuth client application that can request tokens on behalf
 * of users or on its own behalf (client credentials grant).
 */
final class Client
{
    private function __construct(
        private ClientState $state,
    ) {
    }

    /**
     * Create a new OAuth client.
     *
     * @param string[] $redirectUris
     */
    /**
     * @param string[] $redirectUris
     * @param ?PublicId $publicId Pre-assigned public ID for seeding/importing
     *                           clients with a known identity (e.g. dev setup).
     *                           Defaults to a freshly generated NanoID.
     */
    public static function create(
        string $name,
        array $redirectUris,
        ?string $secret = null,
        bool $confidential = false,
        bool $firstParty = false,
        bool $personalAccessClient = false,
        bool $passwordClient = false,
        bool $deviceClient = false,
        ?Uuid $userId = null,
        ?\App\Shared\Domain\Model\PublicId $publicId = null,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Client name cannot be empty.');
        }

        if ($confidential && $secret === null) {
            throw new InvalidArgumentException('Confidential clients must have a secret.');
        }

        return new self(new ClientState(
            id: new Uuid(),
            publicId: $publicId ?? new \App\Shared\Domain\Model\PublicId(),
            name: $name,
            secret: $secret,
            redirectUris: $redirectUris,
            personalAccessClient: $personalAccessClient,
            passwordClient: $passwordClient,
            deviceClient: $deviceClient,
            confidential: $confidential,
            firstParty: $firstParty,
            userId: $userId,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a Client from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(ClientState $state): self
    {
        return new self($state);
    }

    /**
     * Create a personal access client (non-confidential, first-party).
     */
    public static function createPersonalAccess(string $name, ?Uuid $userId = null): self
    {
        return self::create(
            name: $name,
            redirectUris: ['http://localhost'],
            firstParty: true,
            personalAccessClient: true,
            userId: $userId,
        );
    }

    public function revoke(): void
    {
        if ($this->state->revoked) {
            return;
        }

        $this->state->revoked = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Client name cannot be empty.');
        }

        $this->state->name = $name;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateRedirectUris(array $redirectUris): void
    {
        $this->state->redirectUris = $redirectUris;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateSecret(string $secret): void
    {
        $this->state->secret = $secret;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): \App\Shared\Domain\Model\PublicId
    {
        return $this->state->publicId;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getSecret(): ?string
    {
        return $this->state->secret;
    }

    /**
     * @return string[]
     */
    public function getRedirectUris(): array
    {
        return $this->state->redirectUris;
    }

    public function isPersonalAccessClient(): bool
    {
        return $this->state->personalAccessClient;
    }

    public function isPasswordClient(): bool
    {
        return $this->state->passwordClient;
    }

    public function isDeviceClient(): bool
    {
        return $this->state->deviceClient;
    }

    public function isConfidential(): bool
    {
        return $this->state->confidential;
    }

    public function isFirstParty(): bool
    {
        return $this->state->firstParty;
    }

    public function isRevoked(): bool
    {
        return $this->state->revoked;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getUserId(): ?Uuid
    {
        return $this->state->userId;
    }

    public function isOwnedBy(Uuid $userId): bool
    {
        if ($this->state->userId === null) {
            return false;
        }

        return $this->state->userId->equals($userId);
    }

    public function getState(): ClientState
    {
        return $this->state;
    }
}
