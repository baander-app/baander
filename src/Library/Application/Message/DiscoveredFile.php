<?php

declare(strict_types=1);

namespace App\Library\Application\Message;

use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;

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
        return MediaContainer::forExtension($this->extension)?->kind() === MediaKind::Audio;
    }

    public function isVideo(): bool
    {
        return MediaContainer::forExtension($this->extension)?->kind() === MediaKind::Video;
    }
}
