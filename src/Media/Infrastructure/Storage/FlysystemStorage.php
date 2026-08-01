<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\StoredFile;

/**
 * File storage abstraction using local filesystem.
 *
 * Designed to be swapped for Flysystem adapters when cloud storage is needed.
 */
final class FlysystemStorage implements StoragePortInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * Store an uploaded file to the storage path.
     */
    public function store(string $sourcePath, string $relativeDestination): StoredFile
    {
        $destination = $this->basePath . '/' . ltrim($relativeDestination, '/');

        $this->guardPathTraversal($destination);

        if (!copy($sourcePath, $destination)) {
            throw new \RuntimeException(sprintf('Failed to copy file to "%s".', $destination));
        }

        $mimeType = mime_content_type($destination);
        $size = filesize($destination);

        return new StoredFile(
            $relativeDestination,
            $mimeType !== false ? $mimeType : 'application/octet-stream',
            $size !== false ? $size : 0,
        );
    }

    /**
     * Store in-memory binary data to the storage path.
     */
    public function storeFromBytes(string $contents, string $relativeDestination): StoredFile
    {
        $destination = $this->basePath . '/' . ltrim($relativeDestination, '/');

        $this->guardPathTraversal($destination);

        if (file_put_contents($destination, $contents) === false) {
            throw new \RuntimeException(sprintf('Failed to write file to "%s".', $destination));
        }

        $mimeType = mime_content_type($destination);
        $size = filesize($destination);

        return new StoredFile(
            $relativeDestination,
            $mimeType !== false ? $mimeType : 'application/octet-stream',
            $size !== false ? $size : 0,
        );
    }

    /**
     * Delete a file from storage.
     */
    public function delete(string $relativePath): void
    {
        $fullPath = $this->basePath . '/' . ltrim($relativePath, '/');

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Check if a file exists in storage.
     */
    public function exists(string $relativePath): bool
    {
        return file_exists($this->basePath . '/' . ltrim($relativePath, '/'));
    }

    /**
     * Get the full filesystem path for a relative storage path.
     */
    public function fullPath(string $relativePath): string
    {
        return $this->basePath . '/' . ltrim($relativePath, '/');
    }

    public function resolve(string $relativePath): string
    {
        return $this->basePath . '/' . ltrim($relativePath, '/');
    }

    public function deleteDerived(string $relativePath, string $extension): void
    {
        $fullPath = $this->basePath . '/' . ltrim($relativePath, '/');
        $directory = dirname($fullPath);
        $filename = pathinfo($fullPath, PATHINFO_FILENAME);

        // Delete unconditional WebP: {filename}.webp
        $webpPath = $directory . '/' . $filename . '.webp';
        if (file_exists($webpPath) && $webpPath !== $fullPath) {
            unlink($webpPath);
        }

        // Delete preset variants: iterate PRESETS keys from GdImageConverter to stay in sync
        foreach (array_keys(\App\Media\Infrastructure\Converter\GdImageConverter::PRESETS) as $preset) {
            $presetPath = $directory . '/' . $filename . '_' . $preset . '.webp';
            if (file_exists($presetPath)) {
                unlink($presetPath);
            }
        }
    }

    /**
     * Guard against path traversal attacks by verifying the resolved
     * destination stays within the storage base path.
     */
    private function guardPathTraversal(string $destination): void
    {
        $realBase = realpath($this->basePath);
        if ($realBase === false) {
            // Base path does not exist yet — create it before resolving
            mkdir($this->basePath, 0755, true);
            $realBase = realpath($this->basePath);
        }

        if ($realBase === false) {
            throw new \RuntimeException(sprintf('Storage base path "%s" could not be resolved.', $this->basePath));
        }

        // Build the expected directory and create it so realpath works
        $directory = dirname($destination);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $realDestination = realpath($destination);

        // If the file doesn't exist yet, resolve its directory instead
        if ($realDestination === false) {
            $realDestination = realpath($directory);
        }

        if ($realDestination === false || !str_starts_with($realDestination, $realBase)) {
            throw new \RuntimeException(sprintf('Path traversal detected: "%s" resolves outside storage root.', $destination));
        }
    }
}
