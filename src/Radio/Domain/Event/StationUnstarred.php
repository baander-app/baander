<?php

declare(strict_types=1);

namespace App\Radio\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class StationUnstarred extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Uuid $stationId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            Uuid::fromString($payload['station_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'station_id' => $this->stationId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'radio.station_unstarred';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getStationId(): Uuid
    {
        return $this->stationId;
    }
}
