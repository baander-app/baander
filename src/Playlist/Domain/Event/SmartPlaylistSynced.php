<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class SmartPlaylistSynced extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $playlistId,
        private readonly int $songCount,
        private readonly array $rulesApplied,
        private readonly ?Uuid $userId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['playlist_id']),
            (int) $payload['song_count'],
            $payload['rules_applied'],
            isset($payload['user_id']) ? Uuid::fromString($payload['user_id']) : null,
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'playlist_id' => $this->playlistId->toString(),
            'song_count' => $this->songCount,
            'rules_applied' => $this->rulesApplied,
            'user_id' => $this->userId?->toString(),
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'smart_playlist.synced';
    }

    public function getPlaylistId(): Uuid
    {
        return $this->playlistId;
    }

    public function getSongCount(): int
    {
        return $this->songCount;
    }

    public function getRulesApplied(): array
    {
        return $this->rulesApplied;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }
}
