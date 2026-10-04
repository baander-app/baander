<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class AudioPreferences
{
    private function __construct(
        private AudioPreferencesState $state,
    ) {
    }

    /** @param array<array-key, mixed> $payload */
    public static function create(Uuid $userId, array $payload = [], int $version = 1): self
    {
        $now = new DateTimeImmutable();

        return new self(new AudioPreferencesState(
            id: Uuid::generate(),
            userId: $userId,
            payload: $payload,
            version: $version,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(AudioPreferencesState $state): self
    {
        return new self($state);
    }

    /**
     * Replace the payload and advance the version.
     * @param array<array-key, mixed> $payload
     */
    public function updatePayload(array $payload, int $version): void
    {
        $this->state->payload = $payload;
        $this->state->version = $version;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    /** @return array<array-key, mixed> */
    public function getPayload(): array
    {
        return $this->state->payload;
    }

    public function getVersion(): int
    {
        return $this->state->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): AudioPreferencesState
    {
        return $this->state;
    }
}
