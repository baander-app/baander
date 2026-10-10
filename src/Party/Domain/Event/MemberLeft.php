<?php

declare(strict_types=1);

namespace App\Party\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/** A member left a party session, over HTTP or the WebSocket. */
final readonly class MemberLeft extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $sessionId,
        private readonly Uuid $userId,
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
            Uuid::fromString($payload['session_id']),
            Uuid::fromString($payload['user_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    /**
     * @return array{session_id: string, user_id: string, occurred_at: string}
     */
    public function toPayload(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'user_id' => $this->userId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'party.member_left';
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }
}
