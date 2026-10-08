<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Domain\ValueObject\LibraryPath;
use App\Shared\Application\Exception\InvalidInputException;

final class PathValidator
{
    /**
     * Checks a path an operator entered, before a library is created with it:
     * POST /api/libraries/validate-path and `app:library:validate-path`.
     *
     * @throws InvalidInputException when the path is blank
     */
    public function validateInput(string $path): PathValidationResult
    {
        if (trim($path) === '') {
            throw new InvalidInputException('Path is required.');
        }

        try {
            $libraryPath = new LibraryPath($path);
        } catch (\InvalidArgumentException $exception) {
            return new PathValidationResult(valid: false, error: $exception->getMessage());
        }

        return $this->validate($libraryPath);
    }

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
