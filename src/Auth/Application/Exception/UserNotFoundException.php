<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

final class UserNotFoundException extends RuntimeException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self(sprintf('User "%s" not found.', $identifier));
    }
}
