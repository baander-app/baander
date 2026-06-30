<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\Passkey;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Passkey
{
    private function __construct(
        private PasskeyState $state,
    ) {
    }

    /**
     * Create a new passkey via registration.
     */
    public static function create(
        Uuid $id,
        string $name,
        string $credentialId,
        array $data,
        int $counter,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Passkey name cannot be empty.');
        }

        if ($credentialId === '') {
            throw new InvalidArgumentException('Credential ID cannot be empty.');
        }

        $now = new DateTimeImmutable();

        return new self(new PasskeyState(
            id: $id,
            name: $name,
            credentialId: $credentialId,
            data: $data,
            counter: $counter,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    /**
     * Reconstitute a Passkey from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(PasskeyState $state): self
    {
        return new self($state);
    }

    /**
     * Mark this passkey as having been used for authentication.
     */
    public function markUsed(): void
    {
        $this->state->lastUsedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update the sign counter after authentication.
     */
    public function updateCounter(int $counter): void
    {
        $this->state->counter = $counter;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getCredentialId(): string
    {
        return $this->state->credentialId;
    }

    public function getData(): array
    {
        return $this->state->data;
    }

    public function getCounter(): int
    {
        return $this->state->counter;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->state->lastUsedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): PasskeyState
    {
        return $this->state;
    }
}
