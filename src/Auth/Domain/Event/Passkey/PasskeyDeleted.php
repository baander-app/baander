<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event\Passkey;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class PasskeyDeleted extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Uuid $passkeyId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            Uuid::fromString($payload['passkey_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'passkey_id' => $this->passkeyId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'user.passkey_deleted';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getPasskeyId(): Uuid
    {
        return $this->passkeyId;
    }
}
