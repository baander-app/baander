<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * The use case rejects the input it was given. The message says why.
 *
 * HTTP reports it as 422 and console commands exit with INVALID. A context exception
 * for rejected input may extend it to get the same outcome on both paths. If it also implements
 * TranslatableInterface, HTTP reports its translation in the request locale; getMessage()
 * stays English for console output and logs.
 */
class InvalidInputException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details reported as the error envelope's `details`, such as messages per field
     */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
