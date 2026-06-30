<?php

declare(strict_types=1);

namespace App\Media\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Image
{
    private function __construct(
        private ImageState $state,
    ) {
    }

    public static function create(
        string $path,
        string $extension,
        string $mimeType,
        int $size,
        int $width,
        int $height,
        string $imageableType,
        ?Uuid $albumId = null,
        ?Uuid $artistId = null,
        ?Uuid $playlistId = null,
    ): self {
        if ($path === '') {
            throw new InvalidArgumentException('Image path cannot be empty.');
        }

        return new self(new ImageState(
            id: new Uuid(),
            publicId: new PublicId(),
            path: $path,
            extension: $extension,
            mimeType: $mimeType,
            blurhash: null,
            size: $size,
            width: $width,
            height: $height,
            imageableType: $imageableType,
            albumId: $albumId,
            artistId: $artistId,
            playlistId: $playlistId,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(ImageState $state): self
    {
        return new self($state);
    }

    public function setBlurhash(string $blurhash): void
    {
        $this->state->blurhash = $blurhash;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updatePath(string $path): void
    {
        $this->state->path = $path;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getPath(): string
    {
        return $this->state->path;
    }

    public function getExtension(): string
    {
        return $this->state->extension;
    }

    public function getMimeType(): string
    {
        return $this->state->mimeType;
    }

    public function getBlurhash(): ?string
    {
        return $this->state->blurhash;
    }

    public function getSize(): int
    {
        return $this->state->size;
    }

    public function getWidth(): int
    {
        return $this->state->width;
    }

    public function getHeight(): int
    {
        return $this->state->height;
    }

    public function getImageableType(): string
    {
        return $this->state->imageableType;
    }

    public function getAlbumId(): ?Uuid
    {
        return $this->state->albumId;
    }

    public function getArtistId(): ?Uuid
    {
        return $this->state->artistId;
    }

    public function getPlaylistId(): ?Uuid
    {
        return $this->state->playlistId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }
}
