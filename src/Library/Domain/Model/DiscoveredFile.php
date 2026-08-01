<?php

declare(strict_types=1);

namespace App\Library\Domain\Model;

/**
 * Value object representing a discovered file on disk.
 * Carried in FilesDiscovered events for Catalog consumption.
 */
final readonly class DiscoveredFile
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly string $extension,
        public readonly int $size,
        public readonly int $modifiedAt,
        public readonly string $hash,
    ) {
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
