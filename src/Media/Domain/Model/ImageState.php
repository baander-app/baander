<?php

declare(strict_types=1);

namespace App\Media\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class ImageState
{
    public function __construct(
        public Uuid $id,
        public PublicId $publicId,
        public string $path,
        public string $extension,
        public string $mimeType,
        public ?string $blurhash,
        public int $size,
        public int $width,
        public int $height,
        public string $imageableType,
        public ?Uuid $albumId,
        public ?Uuid $artistId,
        public ?Uuid $playlistId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
