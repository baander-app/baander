<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class AlbumCreated extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $albumId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'album.created';
    }

    public function getAlbumId(): Uuid
    {
        return $this->albumId;
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['album_id']),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'album_id' => $this->albumId->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}