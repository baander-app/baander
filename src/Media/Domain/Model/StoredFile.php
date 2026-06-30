<?php

declare(strict_types=1);

namespace App\Media\Domain\Model;

use App\Shared\Domain\Model\Uuid;

/**
 * Value object representing a file stored on disk.
 */
final readonly class StoredFile
{
    public function __construct(
        private string $path,
        private string $mimeType,
        private int $size,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getExtension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    public function exists(): bool
    {
        return file_exists($this->path) && is_readable($this->path);
    }

    public function getSizeFormatted(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
