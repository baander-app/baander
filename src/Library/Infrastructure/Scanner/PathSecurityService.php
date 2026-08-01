<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Scanner;

final class PathSecurityService
{
    /**
     * Validate that a given path is safe and does not escape the library root.
     */
    public function isPathSafe(string $path, string $root): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);

        if ($realPath === false || $realRoot === false) {
            return false;
        }

        return str_starts_with($realPath . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)
            || $realPath === $realRoot;
    }

    /**
     * Validate that a relative path does not contain traversal sequences.
     */
    public function isRelativePathSafe(string $relativePath): bool
    {
        // Normalize directory separators
        $normalized = str_replace('\\', '/', $relativePath);

        // Block traversal sequences
        if (str_contains($normalized, '..')) {
            return false;
        }

        // Block absolute paths
        if (str_starts_with($normalized, '/')) {
            return false;
        }

        return true;
    }
}
