<?php

declare(strict_types=1);

namespace App\Radio\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class CountryUnsubscribed extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Uuid $sourceId,
        private readonly string $countryCode,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            Uuid::fromString($payload['source_id']),
            $payload['country_code'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'source_id' => $this->sourceId->toString(),
            'country_code' => $this->countryCode,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'radio.country_unsubscribed';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getSourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }
}
