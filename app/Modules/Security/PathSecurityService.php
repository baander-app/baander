<?php

namespace App\Modules\Security;

use App\Modules\Security\Exceptions\PathSecurityException;
use Illuminate\Support\Facades\File;

class PathSecurityService
{
    /**
     * Validate if a path is valid for library usage
     *
     * @throws PathSecurityException
     */
    public function isValidLibraryPath(string $path, array $allowedBasePaths): bool
    {
        $this->validatePathSyntax($path);

        $resolvedPath = $this->resolveAndValidateSymlink($path);
        $depth = $this->calculateDirectoryDepth($resolvedPath);

        if (!$this->isWithinAllowedPath($resolvedPath, $allowedBasePaths)) {
            throw PathSecurityException::symlinkOutsideAllowedPaths($resolvedPath, $allowedBasePaths);
        }

        $maxDepth = config('scanner.security.max_directory_depth', 20);
        if ($depth > $maxDepth) {
            throw PathSecurityException::depthExceeded($resolvedPath, $depth, $maxDepth);
        }

        return true;
    }

    /**
     * Resolve symlinks and validate for circular references
     *
     * @throws PathSecurityException
     */
    public function resolveAndValidateSymlink(string $path, array $visited = []): string
    {
        if (!File::exists($path)) {
            throw PathSecurityException::pathNotExists($path);
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw PathSecurityException::pathNotReadable($path);
        }

        // Check for circular symlinks
        if (in_array($realPath, $visited, true)) {
            throw PathSecurityException::circularSymlink($path);
        }

        // If this is a symlink, recurse to check its target
        if ($realPath !== $path && is_link($path)) {
            $visited[] = $realPath;
            return $this->resolveAndValidateSymlink($realPath, $visited);
        }

        return $this->sanitizePath($realPath);
    }

    /**
     * Calculate directory depth from root
     */
    public function calculateDirectoryDepth(string $path): int
    {
        $path = $this->sanitizePath($path);
        $components = array_filter(explode(DIRECTORY_SEPARATOR, $path), fn($part) => $part !== '');

        return count($components);
    }

    /**
     * Check if path is within allowed base paths
     */
    public function isWithinAllowedPath(string $path, array $allowedPaths): bool
    {
        $path = $this->sanitizePath($path);

        foreach ($allowedPaths as $allowedPath) {
            $allowedPath = $this->sanitizePath($allowedPath);

            if (str_starts_with($path, $allowedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize path by removing null bytes and excessive separators
     */
    public function sanitizePath(string $path): string
    {
        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Normalize directory separators
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        // Remove redundant separators (but preserve leading separator for absolute paths)
        $path = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#', DIRECTORY_SEPARATOR, $path);

        // Remove trailing separator
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        return $path;
    }

    /**
     * Validate path syntax and detect traversal attempts
     *
     * @throws PathSecurityException
     */
    private function validatePathSyntax(string $path): void
    {
        // Check for null bytes
        if (str_contains($path, "\0")) {
            throw PathSecurityException::invalidPath($path);
        }

        // Check for directory traversal patterns
        $traversalPatterns = [
            '../',
            '..\\',
            './',
            '.\\',
            '%2e%2e', // URL encoded ../
            '%2e%2e%2f', // URL encoded ../
            '..%2f',
            '%2e%2e%5c',
        ];

        $normalizedPath = strtolower($path);
        foreach ($traversalPatterns as $pattern) {
            if (str_contains($normalizedPath, strtolower($pattern))) {
                throw PathSecurityException::pathTraversalAttempt($path);
            }
        }

        // Check for encoded null bytes
        if (preg_match('/%00/i', $path)) {
            throw PathSecurityException::pathTraversalAttempt($path);
        }
    }
}
