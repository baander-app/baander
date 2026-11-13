<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The use case could not be completed now, and running it again may succeed.
 *
 * Either a service it depends on did not answer, such as the lyrics provider, and nothing was
 * decided; or it did part of its work and could not finish the rest, such as a cleanup that
 * left jobs behind, and the details say what was done. Neither is a verdict on what was asked,
 * unlike a not-found result.
 *
 * HTTP reports it as 503 and console commands exit with FAILURE.
 */
class ServiceUnavailableException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details reported as the error envelope's `details`, such as a `reason` code or the counts of a partly finished run
     */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
