<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class SongMetadataUpdated extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $songId,
        private readonly string $source,
        private readonly array $updatedFields,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['song_id']),
            $payload['source'],
            $payload['updated_fields'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'song_id' => $this->songId->toString(),
            'source' => $this->source,
            'updated_fields' => $this->updatedFields,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'song.metadata_updated';
    }

    public function getSongId(): Uuid
    {
        return $this->songId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getUpdatedFields(): array
    {
        return $this->updatedFields;
    }
}
