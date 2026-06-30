<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class ServerRegistered extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $serverId,
        private readonly PublicId $serverPublicId,
        private readonly string $serverUrl,
        private readonly string $name,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['server_id']),
            PublicId::fromString($payload['server_public_id']),
            $payload['server_url'],
            $payload['name'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'server_id' => $this->serverId->toString(),
            'server_public_id' => $this->serverPublicId->toString(),
            'server_url' => $this->serverUrl,
            'name' => $this->name,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'discovery.server_registered';
    }

    public function getServerId(): Uuid
    {
        return $this->serverId;
    }

    public function getServerPublicId(): PublicId
    {
        return $this->serverPublicId;
    }

    public function getServerUrl(): string
    {
        return $this->serverUrl;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
