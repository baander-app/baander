<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioSource;

use App\Radio\Domain\ValueObject\SyncConfig;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class RadioSource
{
    private array $pendingEvents = [];

    private function __construct(
        private RadioSourceState $state,
    ) {
    }

    public static function create(
        string $name,
        string $type,
        SyncConfig $syncConfig,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Radio source name cannot be empty.');
        }

        if (trim($type) === '') {
            throw new InvalidArgumentException('Radio source type cannot be empty.');
        }

        $now = new DateTimeImmutable();

        return new self(new RadioSourceState(
            id: new Uuid(),
            name: $name,
            type: $type,
            syncConfig: $syncConfig,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(RadioSourceState $state): self
    {
        return new self($state);
    }

    public function deactivate(): void
    {
        $this->state->isActive = false;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * @return list<object>
     */
    public function drainPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getType(): string
    {
        return $this->state->type;
    }

    public function getSyncConfig(): SyncConfig
    {
        return $this->state->syncConfig;
    }

    public function isActive(): bool
    {
        return $this->state->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): RadioSourceState
    {
        return $this->state;
    }
}
