<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Cover;

use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\StoredFile;

/**
 * Image storage in a directory, laid out as FlysystemStorage lays it out. Deleting derived
 * files removes the plain WebP next to the image.
 */
final class DirectoryStorage implements StoragePortInterface
{
    public ?\Throwable $deleteFailure = null;

    public function __construct(private readonly string $root)
    {
    }

    public function store(string $sourcePath, string $relativeDestination): StoredFile
    {
        $destination = $this->prepare($relativeDestination);
        if (!copy($sourcePath, $destination)) {
            throw new \RuntimeException('copy failed');
        }

        return new StoredFile($relativeDestination, 'image/jpeg', (int) filesize($destination));
    }

    public function storeFromBytes(string $contents, string $relativeDestination): StoredFile
    {
        file_put_contents($this->prepare($relativeDestination), $contents);

        return new StoredFile($relativeDestination, 'image/jpeg', strlen($contents));
    }

    public function delete(string $relativePath): void
    {
        if ($this->deleteFailure !== null) {
            throw $this->deleteFailure;
        }
        if (file_exists($this->resolve($relativePath))) {
            unlink($this->resolve($relativePath));
        }
    }

    public function exists(string $relativePath): bool
    {
        return file_exists($this->resolve($relativePath));
    }

    public function resolve(string $relativePath): string
    {
        return $this->root . '/' . ltrim($relativePath, '/');
    }

    public function deleteDerived(string $relativePath, string $extension): void
    {
        $webp = $this->resolve(dirname($relativePath) . '/' . pathinfo($relativePath, PATHINFO_FILENAME) . '.webp');
        if (file_exists($webp)) {
            unlink($webp);
        }
    }

    private function prepare(string $relativePath): string
    {
        $destination = $this->resolve($relativePath);
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0777, true);
        }

        return $destination;
    }
}
