<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** Another scan holds the library's scan claim; HTTP answers 409 and console commands fail. */
final class LibraryScanAlreadyRunningException extends ConflictException
{
    public static function forLibrary(string $name): self
    {
        return new self(
            sprintf('A scan is already in progress for the library "%s".', $name),
            ['reason' => 'scan_in_progress'],
        );
    }
}
