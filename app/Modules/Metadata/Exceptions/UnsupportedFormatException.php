<?php

namespace App\Modules\Metadata\Exceptions;

use Exception;

class UnsupportedFormatException extends Exception
{
    public static function forFile(string $filePath, string $format = 'unknown'): self
    {
        return new self(
            "Unsupported or unknown file format: {$format} for file: {$filePath}"
        );
    }

    public static function noReaderAvailable(string $format): self
    {
        return new self(
            "No reader available for format: {$format}"
        );
    }
}
