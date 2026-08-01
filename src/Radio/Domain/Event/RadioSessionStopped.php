<?php

declare(strict_types=1);

namespace App\Radio\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class RadioSessionStopped extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'radio.session_stopped';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }
}
