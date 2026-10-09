<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The disable committed, but the web server did not close the user's open
 * WebSocket connections. Disabling the user again retries the close.
 */
final class LiveConnectionsNotClosedException extends RuntimeException
{
    public static function forUser(string $identifier, Throwable $reason): self
    {
        return new self(sprintf(
            'User "%s" is disabled and its tokens are revoked, but its open WebSocket connections were not closed: %s. '
            . 'Disable the user again to retry.',
            $identifier,
            $reason->getMessage(),
        ), 0, $reason);
    }
}
