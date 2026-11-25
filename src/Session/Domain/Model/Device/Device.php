<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Device;

use App\Session\Domain\Event\DeviceRegistered;
use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class Device
{
    /**
     * @var list<AbstractDomainEvent>
     */
    private array $pendingEvents = [];

    private function __construct(
        private DeviceState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        Uuid $deviceId,
        ?string $name = null,
    ): self {
        $now = new DateTimeImmutable();

        $device = new self(new DeviceState(
            id: new Uuid(),
            publicId: new PublicId(),
            userId: $userId,
            deviceId: $deviceId,
            name: $name,
            lastSeenAt: $now,
            createdAt: $now,
        ));

        $device->pendingEvents[] = new DeviceRegistered(
            userId: $userId,
            deviceId: $deviceId,
            occurredAt: $now,
        );

        return $device;
    }

    public static function reconstitute(DeviceState $state): self
    {
        return new self($state);
    }

    public function rename(string $name): void
    {
        $this->state->name = $name;
    }

    public function touch(): void
    {
        $this->state->lastSeenAt = new DateTimeImmutable();
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

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getDeviceId(): Uuid
    {
        return $this->state->deviceId;
    }

    public function getName(): ?string
    {
        return $this->state->name;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->state->lastSeenAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getState(): DeviceState
    {
        return $this->state;
    }
}
