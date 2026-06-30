<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class EmailVerified extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Email $email,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            Email::fromString($payload['email']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'email' => $this->email->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'user.email_verified';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }
}
