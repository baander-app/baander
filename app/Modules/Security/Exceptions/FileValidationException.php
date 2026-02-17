<?php

namespace App\Modules\Security\Exceptions;

use Exception;

class FileValidationException extends Exception
{
    public static function fileTooLarge(string $path, int $size, int $maxSize): self
    {
        $sizeMb = round($size / 1024 / 1024, 2);
        $maxSizeMb = round($maxSize / 1024 / 1024, 2);

        return new self(sprintf(
            'File size exceeds limit. Path: %s, Size: %dMB, Max: %dMB',
            $path,
            $sizeMb,
            $maxSizeMb
        ));
    }

    public static function invalidMagicBytes(string $path, string $expectedFormat): self
    {
        return new self(sprintf(
            'File magic bytes do not match expected format. Path: %s, Expected: %s',
            $path,
            $expectedFormat
        ));
    }

    public static function mimeMismatch(string $path, string $declaredMime, string $actualMime): self
    {
        return new self(sprintf(
            'MIME type mismatch. Path: %s, Declared: %s, Actual: %s',
            $path,
            $declaredMime,
            $actualMime
        ));
    }

    public static function unsupportedFormat(string $path): self
    {
        return new self("Unsupported file format: {$path}");
    }

    public static function maxFilesExceeded(int $count, int $maxFiles): self
    {
        return new self(sprintf(
            'Maximum file limit exceeded. Count: %d, Max: %d',
            $count,
            $maxFiles
        ));
    }
}
