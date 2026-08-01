<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Playlist
{
    /** @var PlaylistSong[] */
    private array $songs = [];

    private function __construct(
        private readonly Uuid $id,
        private readonly PublicId $publicId,
        private readonly Uuid $userId,
        private string $name,
        private ?string $description,
        private bool $isPublic,
        private bool $isCollaborative,
        private bool $isSmart,
        private array $smartRules,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Create a new Playlist aggregate root.
     */
    public static function create(
        string $name,
        Uuid $userId,
        ?string $description = null,
        bool $isPublic = false,
        bool $isCollaborative = false,
        bool $isSmart = false,
        array $smartRules = [],
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Playlist name cannot be empty.');
        }

        return new self(
            new Uuid(),
            new PublicId(),
            $userId,
            $name,
            $description,
            $isPublic,
            $isCollaborative,
            $isSmart,
            $smartRules,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }

    /**
     * Reconstitute a Playlist from persistence.
     *
     * This is intended for use by the repository layer only.
     *
     * @param PlaylistSong[] $songs
     */
    public static function reconstitute(
        Uuid $id,
        PublicId $publicId,
        Uuid $userId,
        string $name,
        ?string $description,
        bool $isPublic,
        bool $isCollaborative,
        bool $isSmart,
        array $smartRules,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        array $songs = [],
    ): self {
        $playlist = new self(
            $id,
            $publicId,
            $userId,
            $name,
            $description,
            $isPublic,
            $isCollaborative,
            $isSmart,
            $smartRules,
            $createdAt,
            $updatedAt,
        );
        $playlist->songs = $songs;

        return $playlist;
    }

    /**
     * Update playlist metadata fields.
     */
    public function updateMetadata(
        string $name,
        ?string $description,
        bool $isPublic,
    ): void {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Playlist name cannot be empty.');
        }

        $this->name = $name;
        $this->description = $description;
        $this->isPublic = $isPublic;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Add a song to the playlist.
     */
    public function addSong(Uuid $songId, int $position): void
    {
        foreach ($this->songs as $existingSong) {
            if ($existingSong->getSongId()->equals($songId)) {
                throw new InvalidArgumentException(
                    sprintf('Song %s already exists in this playlist.', $songId->toString()),
                );
            }
        }

        $this->songs[] = new PlaylistSong($songId, $position);
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Remove a song from the playlist.
     */
    public function removeSong(Uuid $songId): void
    {
        $this->songs = array_values(array_filter(
            $this->songs,
            static fn(PlaylistSong $s): bool => !$s->getSongId()->equals($songId),
        ));

        // Re-index positions after removal
        foreach ($this->songs as $index => $song) {
            $this->songs[$index] = new PlaylistSong($song->getSongId(), $index);
        }

        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Reorder all songs according to the given song ID sequence.
     *
     * @param Uuid[] $songIds
     */
    public function reorderSongs(array $songIds): void
    {
        $existingById = [];
        foreach ($this->songs as $song) {
            $existingById[$song->getSongId()->toString()] = $song;
        }

        $reordered = [];
        foreach ($songIds as $position => $songId) {
            $key = $songId->toString();
            if (!isset($existingById[$key])) {
                throw new InvalidArgumentException(
                    sprintf('Song %s is not in this playlist.', $key),
                );
            }
            $reordered[] = new PlaylistSong($songId, $position);
        }

        $this->songs = $reordered;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Remove all songs from the playlist.
     */
    public function clearSongs(): void
    {
        $this->songs = [];
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update smart playlist rules.
     */
    public function updateSmartRules(array $rules): void
    {
        if (!$this->isSmart) {
            throw new InvalidArgumentException('Cannot set smart rules on a non-smart playlist.');
        }

        $this->smartRules = $rules;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function isCollaborative(): bool
    {
        return $this->isCollaborative;
    }

    public function isSmart(): bool
    {
        return $this->isSmart;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSmartRules(): array
    {
        return $this->smartRules;
    }

    /**
     * @return PlaylistSong[]
     */
    public function getSongs(): array
    {
        return $this->songs;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
