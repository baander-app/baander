<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Exception;

final class EmptyOutputDirectoryException extends \InvalidArgumentException
{
    public static function create(): self
    {
        return new self('Output directory cannot be empty.');
    }
}
