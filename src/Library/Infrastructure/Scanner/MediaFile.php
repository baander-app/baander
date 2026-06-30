<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Scanner;

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

    public function isAudio(): bool
    {
        return match ($this->extension) {
            'mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus' => true,
            default => false,
        };
    }

    public function isVideo(): bool
    {
        return match ($this->extension) {
            'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 'ts' => true,
            default => false,
        };
    }
}
