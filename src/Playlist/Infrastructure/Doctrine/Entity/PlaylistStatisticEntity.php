<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'playlist_statistics')]
class PlaylistStatisticEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PlaylistEntity::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PlaylistEntity $playlist;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $views = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $plays = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $shares = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $favorites = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlaylistEntity $playlist)
    {
        $this->id = new Uuid();
        $this->playlist = $playlist;
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

    public function getViews(): int
    {
        return $this->views;
    }

    public function incrementViews(int $count = 1): void
    {
        $this->views += $count;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPlays(): int
    {
        return $this->plays;
    }

    public function incrementPlays(int $count = 1): void
    {
        $this->plays += $count;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getShares(): int
    {
        return $this->shares;
    }

    public function incrementShares(int $count = 1): void
    {
        $this->shares += $count;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFavorites(): int
    {
        return $this->favorites;
    }

    public function incrementFavorites(int $count = 1): void
    {
        $this->favorites += $count;
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
