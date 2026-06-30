<?php

declare(strict_types=1);

namespace App\Filesystem\Infrastructure\Service;

use App\Filesystem\Application\Port\FileHandle;
use App\Filesystem\Application\Port\LocalFilesystemPortInterface;
use App\Filesystem\Application\Port\ReadOnlyFilesystemPortInterface;

final readonly class ReadOnlyFilesystem implements ReadOnlyFilesystemPortInterface
{
    private const ALLOWED_OPEN_MODES = ['r', 'rb'];

    public function __construct(
        private LocalFilesystemPortInterface $filesystem,
        private string $basePath,
    ) {
    }

    public function resolve(string $path): string
    {
        $resolved = $this->filesystem->resolve($path);

        $realBase = realpath($this->basePath);

        if ($realBase === false) {
            throw new \InvalidArgumentException(sprintf('Library base path does not exist: %s', $this->basePath));
        }

        $realPath = realpath($resolved);

        if ($realPath === false) {
            // Path doesn't exist yet — resolve without realpath and check prefix
            $normalizedBase = rtrim($realBase, '/') . '/';
            $normalizedResolved = rtrim($resolved, '/');

            if (!str_starts_with($normalizedResolved, $normalizedBase) && $normalizedResolved !== $realBase) {
                throw new \InvalidArgumentException(sprintf('Path escapes library base path: %s', $path));
            }

            return $resolved;
        }

        $normalizedBase = rtrim($realBase, '/') . '/';
        $normalizedReal = rtrim($realPath, '/') . '/';

        if (!str_starts_with($normalizedReal, $normalizedBase) && $realPath !== $realBase) {
            throw new \InvalidArgumentException(sprintf('Path escapes library base path: %s', $path));
        }

        return $resolved;
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->exists($path);
    }

    public function size(string $path): int|false
    {
        return $this->filesystem->size($path);
    }

    public function read(string $path): string|false
    {
        return $this->filesystem->read($path);
    }

    public function open(string $path, string $mode = 'r'): FileHandle
    {
        if (!in_array($mode, self::ALLOWED_OPEN_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Read-only filesystem does not allow mode "%s". Allowed modes: %s', $mode, implode(', ', self::ALLOWED_OPEN_MODES)));
        }

        return $this->filesystem->open($path, $mode);
    }

    public function list(string $path): array|false
    {
        $resolved = $this->resolve($path);

        if (!is_dir($resolved)) {
            return false;
        }

        $entries = scandir($resolved);

        if ($entries === false) {
            return false;
        }

        return array_values(array_filter($entries, static fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($this->resolve($path));
    }

    public function isFile(string $path): bool
    {
        return is_file($this->resolve($path));
    }

    public function lastModified(string $path): int|false
    {
        $resolved = $this->resolve($path);

        if (!file_exists($resolved)) {
            return false;
        }

        return filemtime($resolved);
    }
}
