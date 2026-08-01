<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class EqDeviceProfile
{
    private function __construct(
        private EqDeviceProfileState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        string $name,
        string $icon = 'custom',
        ?string $deviceId = null,
        array $payload = [],
        bool $isDefault = false,
        int $sortOrder = 0,
    ): self {
        $now = new DateTimeImmutable();

        return new self(new EqDeviceProfileState(
            id: Uuid::generate(),
            userId: $userId,
            name: $name,
            icon: $icon,
            deviceId: $deviceId,
            payload: $payload,
            isDefault: $isDefault,
            sortOrder: $sortOrder,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(EqDeviceProfileState $state): self
    {
        return new self($state);
    }

    /**
     * Partial update. Passing null for a field leaves it unchanged.
     * Changing the payload bumps the version.
     */
    public function updateDetails(
        ?string $name = null,
        ?string $icon = null,
        ?string $deviceId = null,
        ?array $payload = null,
        ?int $sortOrder = null,
    ): void {
        if ($name !== null) {
            $this->state->name = $name;
        }
        if ($icon !== null) {
            $this->state->icon = $icon;
        }
        if ($deviceId !== null) {
            $this->state->deviceId = $deviceId;
        }
        if ($payload !== null) {
            $this->state->payload = $payload;
            $this->state->version++;
        }
        if ($sortOrder !== null) {
            $this->state->sortOrder = $sortOrder;
        }

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

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getIcon(): string
    {
        return $this->state->icon;
    }

    public function getDeviceId(): ?string
    {
        return $this->state->deviceId;
    }

    public function getPayload(): array
    {
        return $this->state->payload;
    }

    public function isDefault(): bool
    {
        return $this->state->isDefault;
    }

    public function getSortOrder(): int
    {
        return $this->state->sortOrder;
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

    public function getState(): EqDeviceProfileState
    {
        return $this->state;
    }
}
