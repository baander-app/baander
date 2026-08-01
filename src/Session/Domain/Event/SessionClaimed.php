<?php

declare(strict_types=1);

namespace App\Session\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class SessionClaimed extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Uuid $deviceId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            Uuid::fromString($payload['device_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'device_id' => $this->deviceId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'session.claimed';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getDeviceId(): Uuid
    {
        return $this->deviceId;
    }
}
