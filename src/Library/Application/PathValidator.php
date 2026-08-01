<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Domain\ValueObject\LibraryPath;

final class PathValidator
{
    public function validate(LibraryPath $path): PathValidationResult
    {
        $rawPath = $path->toString();

        // Check for path traversal
        if (str_contains($rawPath, '..')) {
            return new PathValidationResult(
                valid: false,
                error: 'Path must not contain traversal sequences (..).',
            );
        }

        $resolved = realpath($rawPath);

        if ($resolved === false) {
            return new PathValidationResult(
                valid: false,
                error: sprintf('Path "%s" does not exist on the filesystem.', $rawPath),
                exists: false,
            );
        }

        if (!is_dir($resolved)) {
            return new PathValidationResult(
                valid: false,
                error: sprintf('Path "%s" is not a directory.', $resolved),
                resolvedPath: $resolved,
                exists: true,
            );
        }

        if (!is_readable($resolved)) {
            return new PathValidationResult(
                valid: false,
                error: sprintf('Path "%s" is not readable. Check file permissions.', $resolved),
                resolvedPath: $resolved,
                exists: true,
                readable: false,
            );
        }

        return new PathValidationResult(
            valid: true,
            resolvedPath: $resolved,
            exists: true,
            readable: true,
        );
    }
}
