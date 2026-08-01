<?php

declare(strict_types=1);

namespace App\Auth\Domain\Event\Passkey;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class PasskeyRegistered extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Uuid $passkeyId,
        private readonly string $credentialId,
        private readonly string $name,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['user_id']),
            Uuid::fromString($payload['passkey_id']),
            $payload['credential_id'],
            $payload['name'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'passkey_id' => $this->passkeyId->toString(),
            'credential_id' => $this->credentialId,
            'name' => $this->name,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'user.passkey_registered';
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getPasskeyId(): Uuid
    {
        return $this->passkeyId;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
