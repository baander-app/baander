<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\NotFoundException;

/** No library has the named UUID or slug; HTTP answers 404 and console commands fail. */
final class LibraryNotFoundException extends NotFoundException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self(sprintf('Library "%s" not found.', $identifier));
    }
}
