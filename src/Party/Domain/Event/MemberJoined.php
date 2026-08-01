<?php

declare(strict_types=1);

namespace App\Party\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class MemberJoined extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $sessionId,
        private readonly Uuid $userId,
        private readonly string $role,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['session_id']),
            Uuid::fromString($payload['user_id']),
            $payload['role'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'user_id' => $this->userId->toString(),
            'role' => $this->role,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'party.member_joined';
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getRole(): string
    {
        return $this->role;
    }
}
