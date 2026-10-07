<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * OAuth 2.0 Client aggregate root.
 *
 * A pre-registered first-party client that password and passkey login issue tokens to.
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
        bool $passwordClient = false,
        ?PublicId $publicId = null,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Client name cannot be empty.');
        }

        if ($confidential && $secret === null) {
            throw new InvalidArgumentException('Confidential clients must have a secret.');
        }

        return new self(new ClientState(
            id: new Uuid(),
            publicId: $publicId ?? new PublicId(),
            name: $name,
            secret: $secret,
            redirectUris: $redirectUris,
            passwordClient: $passwordClient,
            confidential: $confidential,
            firstParty: $firstParty,
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

    /** @param array<array-key, string> $redirectUris */
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

    public function getPublicId(): PublicId
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

    public function isPasswordClient(): bool
    {
        return $this->state->passwordClient;
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

    public function getState(): ClientState
    {
        return $this->state;
    }
}
