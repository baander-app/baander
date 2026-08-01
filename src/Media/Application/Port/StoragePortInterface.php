<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Domain\Model\StoredFile;

interface StoragePortInterface
{
    public function store(string $sourcePath, string $relativeDestination): StoredFile;

    public function storeFromBytes(string $contents, string $relativeDestination): StoredFile;

    public function delete(string $relativePath): void;

    public function exists(string $relativePath): bool;

    /**
     * Resolve a relative storage path to a full filesystem path.
     */
    public function resolve(string $relativePath): string;

    /**
     * Delete all derived (cached) files for a given source image.
     * Removes unconditional WebP and all preset variants.
     */
    public function deleteDerived(string $relativePath, string $extension): void;
}
