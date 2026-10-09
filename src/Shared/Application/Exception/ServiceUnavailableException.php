<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * An external service the use case depends on did not answer, such as the lyrics provider.
 *
 * HTTP reports it as 503 and console commands exit with FAILURE. It tells the caller that
 * nothing was decided and a retry may succeed, unlike a not-found result.
 */
class ServiceUnavailableException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details reported as the error envelope's `details`, such as a `reason` code
     */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
