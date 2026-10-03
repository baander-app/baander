<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Filesystem;

use InvalidArgumentException;

/** Canonical path checks, including the existing ancestors of missing targets. */
final class StoragePathBoundary
{
    public static function resolve(string $basePath, string $candidate): string
    {
        // Do not reuse PHP's cached realpath after a link has been replaced.
        clearstatcache(true);
        $base = self::canonicalPath($basePath);
        $path = self::canonicalPath($candidate);
        if ($path !== $base && !str_starts_with($path, rtrim($base, '/') . '/')) {
            throw new InvalidArgumentException('Path escapes filesystem base path: ' . $candidate);
        }

        return $path;
    }

    private static function canonicalPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Filesystem paths must be nonempty and contain no null bytes.');
        }
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new InvalidArgumentException('Cannot resolve a relative filesystem base path.');
            }
            $path = $cwd . '/' . $path;
        }
        $missing = [];
        $cursor = rtrim($path, '/') ?: '/';
        while (($resolved = realpath($cursor)) === false) {
            // A dangling link is not a missing directory: following it when a
            // later operation creates files could escape the configured root.
            if (is_link($cursor) || file_exists($cursor)) {
                throw new InvalidArgumentException('Cannot safely resolve filesystem path: ' . $path);
            }
            $parent = dirname($cursor);
            $name = basename($cursor);
            if ($parent === $cursor || $name === '.' || $name === '..') {
                throw new InvalidArgumentException('Cannot safely resolve filesystem path: ' . $path);
            }
            $missing[] = $name;
            $cursor = $parent;
        }
        if ($missing !== [] && !is_dir($resolved)) {
            throw new InvalidArgumentException('Filesystem path ancestor is not a directory: ' . $path);
        }

        return $missing === [] ? $resolved : rtrim($resolved, '/') . '/' . implode('/', array_reverse($missing));
    }
}
