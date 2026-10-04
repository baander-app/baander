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
     * @param array<array-key, mixed> $queue
     * @param DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        private readonly Uuid $userId,
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
            $payload['queue'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    /**
     * @return array{user_id: string, queue: array<array-key, mixed>, occurred_at: string}
     */
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

    /**
     * @return array<array-key, mixed>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }
}
