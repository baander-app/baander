<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'media_activities')]
#[ORM\Index(name: 'idx_media_activities_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_media_activities_song_id', columns: ['song_id'])]
#[ORM\Index(name: 'idx_media_activities_album_id', columns: ['album_id'])]
#[ORM\Index(name: 'idx_media_activities_artist_id', columns: ['artist_id'])]
#[ORM\Index(name: 'idx_media_activities_movie_id', columns: ['movie_id'])]
#[ORM\UniqueConstraint(name: 'media_activities_public_id_unique', columns: ['public_id'])]
#[ORM\Index(name: 'idx_media_activities_type_user', columns: ['activity_type', 'user_id'])]
class MediaActivityEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'text')]
    private string $activityType;

    #[ORM\ManyToOne(targetEntity: SongEntity::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SongEntity $song = null;

    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AlbumEntity $album = null;

    #[ORM\ManyToOne(targetEntity: ArtistEntity::class)]
    #[ORM\JoinColumn(name: 'artist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ArtistEntity $artist = null;

    #[ORM\ManyToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MovieEntity $movie = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $playCount = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $love = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPlayedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastPlatform = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastPlayer = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        UserEntity $user,
        string $activityType,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->user = $user;
        $this->activityType = $activityType;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function getActivityType(): string
    {
        return $this->activityType;
    }

    public function setActivityType(string $activityType): void
    {
        $this->activityType = $activityType;
    }

    public function getSong(): ?SongEntity
    {
        return $this->song;
    }

    public function setSong(?SongEntity $song): void
    {
        $this->song = $song;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAlbum(): ?AlbumEntity
    {
        return $this->album;
    }

    public function setAlbum(?AlbumEntity $album): void
    {
        $this->album = $album;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getArtist(): ?ArtistEntity
    {
        return $this->artist;
    }

    public function setArtist(?ArtistEntity $artist): void
    {
        $this->artist = $artist;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMovie(): ?MovieEntity
    {
        return $this->movie;
    }

    public function setMovie(?MovieEntity $movie): void
    {
        $this->movie = $movie;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPlayCount(): ?int
    {
        return $this->playCount;
    }

    public function incrementPlayCount(int $count = 1): void
    {
        $this->playCount = ($this->playCount ?? 0) + $count;
        $this->lastPlayedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isLove(): bool
    {
        return $this->love;
    }

    public function setLove(bool $love): void
    {
        $this->love = $love;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastPlayedAt(): ?\DateTimeImmutable
    {
        return $this->lastPlayedAt;
    }

    public function getLastPlatform(): ?string
    {
        return $this->lastPlatform;
    }

    public function setLastPlatform(?string $lastPlatform): void
    {
        $this->lastPlatform = $lastPlatform;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastPlayer(): ?string
    {
        return $this->lastPlayer;
    }

    public function setLastPlayer(?string $lastPlayer): void
    {
        $this->lastPlayer = $lastPlayer;
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
