<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class MetadataSynced extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $entityId,
        private readonly string $entityType,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'metadata.synced';
    }

    public function getEntityId(): Uuid
    {
        return $this->entityId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['entity_id']),
            $payload['entity_type'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'entity_id' => $this->entityId->toString(),
            'entity_type' => $this->entityType,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}