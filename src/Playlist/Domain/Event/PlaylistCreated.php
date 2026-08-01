<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class PlaylistCreated extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $playlistId,
        private readonly string $name,
        private readonly bool $isPublic,
        private readonly ?Uuid $userId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['playlist_id']),
            $payload['name'],
            (bool) $payload['is_public'],
            isset($payload['user_id']) ? Uuid::fromString($payload['user_id']) : null,
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'playlist_id' => $this->playlistId->toString(),
            'name' => $this->name,
            'is_public' => $this->isPublic,
            'user_id' => $this->userId?->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'playlist.created';
    }

    public function getPlaylistId(): Uuid
    {
        return $this->playlistId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }
}
