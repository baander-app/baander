<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\NotFoundException;

/** No login block has the ID; HTTP answers 404 and console commands fail. */
final class LoginBlockNotFoundException extends NotFoundException
{
    public static function forId(string $id, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Login block "%s" not found.', $id), previous: $previous);
    }
}
