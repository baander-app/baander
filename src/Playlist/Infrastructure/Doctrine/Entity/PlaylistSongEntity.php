<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine\Entity;

use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'playlist_song')]
#[ORM\Index(name: 'idx_playlist_song_playlist_id', columns: ['playlist_id'])]
#[ORM\Index(name: 'idx_playlist_song_song_id', columns: ['song_id'])]
#[ORM\UniqueConstraint(name: 'playlist_song_unique', columns: ['playlist_id', 'song_id'])]
class PlaylistSongEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PlaylistEntity::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PlaylistEntity $playlist;

    #[ORM\ManyToOne(targetEntity: SongEntity::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SongEntity $song;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlaylistEntity $playlist,
        SongEntity $song,
        int $position = 0,
    ) {
        $this->id = new Uuid();
        $this->playlist = $playlist;
        $this->song = $song;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlaylist(): PlaylistEntity
    {
        return $this->playlist;
    }

    public function getSong(): SongEntity
    {
        return $this->song;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
