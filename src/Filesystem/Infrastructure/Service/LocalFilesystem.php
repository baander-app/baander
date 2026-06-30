<?php

declare(strict_types=1);

namespace App\Filesystem\Infrastructure\Service;

use App\Filesystem\Application\Port\FileHandle;
use App\Filesystem\Application\Port\LocalFilesystemPortInterface;
use Swoole\Coroutine\System;

final class LocalFilesystem implements LocalFilesystemPortInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function resolve(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolve($path));
    }

    public function size(string $path): int|false
    {
        $resolved = $this->resolve($path);

        if (!file_exists($resolved)) {
            return false;
        }

        return filesize($resolved);
    }

    public function read(string $path): string|false
    {
        return System::readFile($this->resolve($path));
    }

    public function write(string $path, string $content, int $flags = 0): bool
    {
        $resolved = $this->resolve($path);
        $dir = dirname($resolved);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return System::writeFile($resolved, $content, $flags) !== false;
    }

    public function open(string $path, string $mode): FileHandle
    {
        $resolved = $this->resolve($path);

        if (str_contains($mode, 'w') || str_contains($mode, 'a')) {
            $dir = dirname($resolved);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $resource = fopen($resolved, $mode);

        if ($resource === false) {
            throw new \RuntimeException(sprintf('Failed to open file: %s', $resolved));
        }

        return new FileHandle($resource, $resolved);
    }
}
