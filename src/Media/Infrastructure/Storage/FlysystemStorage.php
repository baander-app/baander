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
        $destination = $this->guardPathTraversal($relativeDestination, true);

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
        $destination = $this->guardPathTraversal($relativeDestination, true);

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
        $fullPath = $this->guardPathTraversal($relativePath);

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Check if a file exists in storage.
     */
    public function exists(string $relativePath): bool
    {
        return file_exists($this->guardPathTraversal($relativePath));
    }

    /**
     * Get the full filesystem path for a relative storage path.
     */
    public function fullPath(string $relativePath): string
    {
        return $this->guardPathTraversal($relativePath);
    }

    public function resolve(string $relativePath): string
    {
        return $this->guardPathTraversal($relativePath);
    }

    public function deleteDerived(string $relativePath, string $extension): void
    {
        $fullPath = $this->guardPathTraversal($relativePath);
        $relativeDirectory = dirname(ltrim($relativePath, '/'));
        $filename = pathinfo($relativePath, PATHINFO_FILENAME);

        // Delete unconditional WebP: {filename}.webp
        $webpPath = $this->guardPathTraversal($relativeDirectory . '/' . $filename . '.webp');
        $derivedPaths = [];
        if (file_exists($webpPath) && $webpPath !== $fullPath) {
            $derivedPaths[] = $webpPath;
        }

        // Delete preset variants: iterate PRESETS keys from GdImageConverter to stay in sync
        foreach (array_keys(\App\Media\Infrastructure\Converter\GdImageConverter::PRESETS) as $preset) {
            $presetPath = $this->guardPathTraversal($relativeDirectory . '/' . $filename . '_' . $preset . '.webp');
            if (file_exists($presetPath)) {
                $derivedPaths[] = $presetPath;
            }
        }
        // Validate every derived path before removing any of them.
        foreach ($derivedPaths as $path) {
            unlink($path);
        }
    }

    /**
     * Guard against path traversal attacks by verifying the resolved
     * destination stays within the storage base path.
     */
    private function guardPathTraversal(string $relativePath, bool $createParent = false): string
    {
        $segments = explode('/', ltrim($relativePath, '/'));
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || in_array('..', $segments, true)) {
            throw new \RuntimeException('Path traversal detected: invalid storage path.');
        }
        $segments = array_values(array_filter($segments, static fn (string $part): bool => $part !== '' && $part !== '.'));
        if ($segments === []) {
            throw new \RuntimeException('Path traversal detected: storage path must identify a file.');
        }

        // Reject invalid input before creating even the storage root.
        clearstatcache(true);
        $realBase = realpath($this->basePath);
        if ($realBase === false) {
            if (!$createParent) {
                return rtrim($this->basePath, '/') . '/' . implode('/', $segments);
            }
            if (!is_dir($this->basePath) && !mkdir($this->basePath, 0755, true) && !is_dir($this->basePath)) {
                throw new \RuntimeException('Unable to create storage root.');
            }
            $realBase = realpath($this->basePath);
        }

        if ($realBase === false) {
            throw new \RuntimeException(sprintf('Storage base path "%s" could not be resolved.', $this->basePath));
        }

        $destination = $realBase;
        foreach ($segments as $part) {
            $destination .= '/' . $part;
            // Check each existing ancestor before creating any missing directory.
            if (file_exists($destination) || is_link($destination)) {
                $resolved = realpath($destination);
                if ($resolved === false || !str_starts_with($resolved, rtrim($realBase, '/') . '/')) {
                    throw new \RuntimeException('Path traversal detected: path resolves outside storage root.');
                }
                $destination = $resolved;
            }
        }
        if ($createParent && !is_dir(dirname($destination)) &&
            !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
            throw new \RuntimeException('Unable to create storage directory.');
        }

        return $destination;
    }
}
