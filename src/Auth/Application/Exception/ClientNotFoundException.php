<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * The client does not exist or is not owned by the requesting user.
 *
 * Both cases share one exception so callers cannot probe other users' clients.
 */
final class ClientNotFoundException extends RuntimeException
{
    public static function forOwner(): self
    {
        return new self('Client not found.');
    }
}
