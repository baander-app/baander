<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine\Entity;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'images')]
#[ORM\Index(name: 'idx_images_album_id', columns: ['album_id'])]
#[ORM\Index(name: 'idx_images_artist_id', columns: ['artist_id'])]
#[ORM\Index(name: 'idx_images_playlist_id', columns: ['playlist_id'])]
#[ORM\UniqueConstraint(name: 'images_public_id_unique', columns: ['public_id'])]
class ImageEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $path;

    #[ORM\Column(type: 'text')]
    private string $extension;

    #[ORM\Column(type: 'text')]
    private string $mimeType;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $blurhash = null;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column(type: 'integer')]
    private int $width;

    #[ORM\Column(type: 'integer')]
    private int $height;

    #[ORM\Column(type: 'text')]
    private string $imageableType;

    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AlbumEntity $album = null;

    #[ORM\ManyToOne(targetEntity: ArtistEntity::class)]
    #[ORM\JoinColumn(name: 'artist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ArtistEntity $artist = null;

    #[ORM\ManyToOne(targetEntity: PlaylistEntity::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PlaylistEntity $playlist = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $path,
        string $extension,
        string $mimeType,
        PublicId $publicId,
        int $size,
        int $width,
        int $height,
        string $imageableType,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->path = $path;
        $this->extension = $extension;
        $this->mimeType = $mimeType;
        $this->blurhash = null;
        $this->publicId = $publicId;
        $this->size = $size;
        $this->width = $width;
        $this->height = $height;
        $this->imageableType = $imageableType;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getBlurhash(): ?string
    {
        return $this->blurhash;
    }

    public function setBlurhash(?string $blurhash): void
    {
        $this->blurhash = $blurhash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setWidth(int $width): void
    {
        $this->width = $width;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setHeight(int $height): void
    {
        $this->height = $height;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getImageableType(): string
    {
        return $this->imageableType;
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

    public function getPlaylist(): ?PlaylistEntity
    {
        return $this->playlist;
    }

    public function setPlaylist(?PlaylistEntity $playlist): void
    {
        $this->playlist = $playlist;
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
