<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\NotFoundException;

/** No user has the named email address or UUID; HTTP answers 404 and console commands fail. */
final class UserNotFoundException extends NotFoundException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self(sprintf('User "%s" not found.', $identifier));
    }
}
