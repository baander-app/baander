<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class PreferenceHistory
{
    private function __construct(
        private PreferenceHistoryState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        string $preferenceType,
        int $version,
        array $payload,
    ): self {
        return new self(new PreferenceHistoryState(
            id: Uuid::generate(),
            userId: $userId,
            preferenceType: $preferenceType,
            version: $version,
            payload: $payload,
            createdAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(PreferenceHistoryState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getPreferenceType(): string
    {
        return $this->state->preferenceType;
    }

    public function getVersion(): int
    {
        return $this->state->version;
    }

    public function getPayload(): array
    {
        return $this->state->payload;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getState(): PreferenceHistoryState
    {
        return $this->state;
    }
}
