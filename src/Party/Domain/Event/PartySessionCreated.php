<?php

declare(strict_types=1);

namespace App\Party\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class PartySessionCreated extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $sessionId,
        private readonly Uuid $hostUserId,
        private readonly Uuid $videoId,
        private readonly int $maxMembers,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['session_id']),
            Uuid::fromString($payload['host_user_id']),
            Uuid::fromString($payload['video_id']),
            (int) $payload['max_members'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'host_user_id' => $this->hostUserId->toString(),
            'video_id' => $this->videoId->toString(),
            'max_members' => $this->maxMembers,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'party.session_created';
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getHostUserId(): Uuid
    {
        return $this->hostUserId;
    }

    public function getVideoId(): Uuid
    {
        return $this->videoId;
    }

    public function getMaxMembers(): int
    {
        return $this->maxMembers;
    }
}
