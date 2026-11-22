<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The disable or deletion committed, but the web server did not close the user's open
 * WebSocket connections. Disabling the user again retries the close; after a deletion the
 * web server's socket reconciliation closes them.
 */
final class LiveConnectionsNotClosedException extends RuntimeException
{
    public static function forDisabledUser(string $identifier, Throwable $reason): self
    {
        return new self(sprintf(
            'User "%s" is disabled and its tokens are revoked, but its open WebSocket connections were not closed: %s. '
            . 'Disable the user again to retry.',
            $identifier,
            $reason->getMessage(),
        ), 0, $reason);
    }

    public static function forDeletedUser(string $identifier, Throwable $reason): self
    {
        return new self(sprintf(
            'User "%s" is deleted, but its open WebSocket connections were not closed: %s. '
            . 'The web server closes them within a minute.',
            $identifier,
            $reason->getMessage(),
        ), 0, $reason);
    }
}
