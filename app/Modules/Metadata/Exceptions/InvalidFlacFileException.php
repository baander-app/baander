<?php

namespace App\Modules\Metadata\Exceptions;

use Exception;

class InvalidFlacFileException extends Exception
{
    public static function invalidSignature(string $filePath, string $signature): self
    {
        return new self(
            "Invalid FLAC signature: " . bin2hex($signature) . " for file: {$filePath}"
        );
    }

    public static function cannotOpenFile(string $filePath): self
    {
        return new self(
            "Cannot open file: {$filePath}"
        );
    }

    public static function parseError(string $filePath, string $reason): self
    {
        return new self(
            "Failed to parse FLAC file: {$filePath} - {$reason}"
        );
    }
}
