<?php

declare(strict_types=1);

namespace App\Library\Application\DTO;

use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;

final readonly class MediaFile
{
    public function __construct(
        private string $absolutePath,
        private string $relativePath,
        private string $extension,
        private int $size,
        private int $modifiedAt,
    ) {
    }

    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getModifiedAt(): int
    {
        return $this->modifiedAt;
    }

    public function isVideoSidecar(): bool
    {
        return self::isVideoSidecarExtension($this->extension);
    }

    public static function isVideoSidecarExtension(string $extension): bool
    {
        return in_array($extension, ['nfo', 'jpg', 'jpeg', 'png', 'webp'], true);
    }

    public function isAudio(): bool
    {
        return MediaContainer::forExtension($this->extension)?->kind() === MediaKind::Audio;
    }

    public function isVideo(): bool
    {
        return MediaContainer::forExtension($this->extension)?->kind() === MediaKind::Video;
    }
}
