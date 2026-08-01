<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

interface MediaAdminPortInterface
{
    /**
     * Get image storage statistics.
     *
     * @return array{totalImages: int, totalSize: int, byType: array<array{type: string, count: int, size: int}>}
     */
    public function getStorageStats(): array;

    /**
     * Dispatch an async job to prune image records whose files no longer exist on disk.
     *
     * @return array{dispatched: bool}
     */
    public function pruneMissingImages(): array;

    /**
     * Check how many images have missing files (dry-run).
     *
     * @return array{totalImages: int, missingCount: int, missingImages: array<array{id: string, path: string, type: string}>}
     */
    public function checkMissingImages(): array;
}
