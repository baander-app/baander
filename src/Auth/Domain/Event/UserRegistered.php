<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class UserRegistered extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly PublicId $publicId,
        private readonly Email $email,
        private readonly string $name,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            PublicId::fromString($payload['public_id']),
            Email::fromString($payload['email']),
            $payload['name'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'public_id' => $this->publicId->toString(),
            'email' => $this->email->toString(),
            'name' => $this->name,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'user.registered';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
