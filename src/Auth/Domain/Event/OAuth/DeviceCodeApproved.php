<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event\OAuth;

use App\Shared\Domain\Event\AbstractDomainEvent;
use DateTimeImmutable;

final readonly class DeviceCodeApproved extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $deviceCodeId,
        private readonly string $userId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'device_code.approved';
    }

    public function getDeviceCodeId(): string
    {
        return $this->deviceCodeId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            $payload['device_code_id'],
            $payload['user_id'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'device_code_id' => $this->deviceCodeId,
            'user_id' => $this->userId,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
