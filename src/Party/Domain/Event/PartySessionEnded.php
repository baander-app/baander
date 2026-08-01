<?php

declare(strict_types=1);

namespace App\Party\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class PartySessionEnded extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $sessionId,
        private readonly Uuid $hostUserId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['session_id']),
            Uuid::fromString($payload['host_user_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'host_user_id' => $this->hostUserId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'party.session_ended';
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getHostUserId(): Uuid
    {
        return $this->hostUserId;
    }
}
