<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The target's current state does not allow the change, such as a scan that is already running.
 *
 * HTTP reports it as 409 and console commands exit with FAILURE. A context exception
 * for such a state may extend it to get the same outcome on both paths.
 */
class ConflictException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details reported as the error envelope's `details`, such as a `reason` code
     */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
