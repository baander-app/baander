<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Event;

use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class PairingCompleted extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $pairingId,
        private readonly PublicId $pairingPublicId,
        private readonly Uuid $serverId,
        private readonly AuthenticationMethod $method,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['pairing_id']),
            PublicId::fromString($payload['pairing_public_id']),
            Uuid::fromString($payload['server_id']),
            AuthenticationMethod::from($payload['method']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'pairing_id' => $this->pairingId->toString(),
            'pairing_public_id' => $this->pairingPublicId->toString(),
            'server_id' => $this->serverId->toString(),
            'method' => $this->method->value,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'discovery.pairing_completed';
    }

    public function getPairingId(): Uuid
    {
        return $this->pairingId;
    }

    public function getPairingPublicId(): PublicId
    {
        return $this->pairingPublicId;
    }

    public function getServerId(): Uuid
    {
        return $this->serverId;
    }

    public function getMethod(): AuthenticationMethod
    {
        return $this->method;
    }
}
