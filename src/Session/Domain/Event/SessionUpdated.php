<?php

declare(strict_types=1);

namespace App\Session\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class SessionUpdated extends AbstractDomainEvent
{
    /**
     * @param Uuid $userId
     * @param Uuid|null $deviceId
     * @param array<array-key, mixed> $queue
     * @param DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        private readonly Uuid $userId,
        private readonly ?Uuid $deviceId,
        private readonly array $queue,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            isset($payload['device_id']) ? Uuid::fromString($payload['device_id']) : null,
            $payload['queue'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    /**
     * @return array{user_id: string, device_id: string|null, queue: array<array-key, mixed>, occurred_at: string}
     */
    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'device_id' => $this->deviceId?->toString(),
            'queue' => $this->queue,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'session.updated';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getDeviceId(): ?Uuid
    {
        return $this->deviceId;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }
}
