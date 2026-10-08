<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The use case's target does not exist. The message names the target.
 *
 * HTTP reports it as 404 and console commands exit with FAILURE. A context exception
 * for a missing target may extend it to get the same outcome on both paths. If it also implements
 * TranslatableInterface, HTTP reports its translation in the request locale; getMessage()
 * stays English for console output and logs.
 */
class NotFoundException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details reported as the error envelope's `details`
     */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
