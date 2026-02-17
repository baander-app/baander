<?php

namespace App\Modules\Security\Exceptions;

use Exception;

class PathSecurityException extends Exception
{
    public static function pathTraversalAttempt(string $path): self
    {
        return new self("Path traversal attempt detected: {$path}");
    }

    public static function symlinkOutsideAllowedPaths(string $path, array $allowedPaths): self
    {
        return new self(sprintf(
            'Symlink resolves outside allowed paths. Path: %s, Allowed: %s',
            $path,
            implode(', ', $allowedPaths)
        ));
    }

    public static function circularSymlink(string $path): self
    {
        return new self("Circular symlink detected at: {$path}");
    }

    public static function depthExceeded(string $path, int $depth, int $maxDepth): self
    {
        return new self(sprintf(
            'Directory depth exceeded. Path: %s, Depth: %d, Max: %d',
            $path,
            $depth,
            $maxDepth
        ));
    }

    public static function pathNotReadable(string $path): self
    {
        return new self("Path is not readable: {$path}");
    }

    public static function pathNotExists(string $path): self
    {
        return new self("Path does not exist: {$path}");
    }

    public static function invalidPath(string $path): self
    {
        return new self("Invalid path provided: {$path}");
    }
}
