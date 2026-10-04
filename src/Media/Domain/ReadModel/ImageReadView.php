<?php

declare(strict_types=1);

namespace App\Media\Domain\ReadModel;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class ImageReadView
{
    public function __construct(
        private Uuid $id,
        private PublicId $publicId,
        private string $path,
        private string $extension,
        private string $mimeType,
        private ?string $blurhash,
        private int $size,
        private int $width,
        private int $height,
        private string $imageableType,
        private ?Uuid $albumId,
        private ?Uuid $artistId,
        private ?Uuid $playlistId,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getPath(): string
    {
        return $this->path;
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

    public function getSize(): int
    {
        return $this->size;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getImageableType(): string
    {
        return $this->imageableType;
    }

    public function getAlbumId(): ?Uuid
    {
        return $this->albumId;
    }

    public function getArtistId(): ?Uuid
    {
        return $this->artistId;
    }

    public function getPlaylistId(): ?Uuid
    {
        return $this->playlistId;
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
