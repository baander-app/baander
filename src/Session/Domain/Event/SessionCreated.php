<?php

declare(strict_types=1);

namespace App\Session\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class SessionCreated extends AbstractDomainEvent
{
    /**
     * @param Uuid $userId
     * @param array $queue
     * @param DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        private readonly Uuid $userId,
        private readonly array $queue,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            $payload['queue'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'queue' => $this->queue,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'session.created';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getQueue(): array
    {
        return $this->queue;
    }
}
