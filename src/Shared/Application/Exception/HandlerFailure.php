<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * Finds the exception a message handler threw behind the bus's wrapper.
 *
 * The bus wraps a handler's exception in HandlerFailedException. The outcome
 * exceptions next to this class decide the HTTP status, the console exit code
 * and the job monitor's failure record, so every caller unwraps the same way.
 */
final class HandlerFailure
{
    /**
     * Unwraps HandlerFailedException, nested ones included, while it wraps a single
     * exception. A wrapper around several handlers' exceptions is returned as is.
     */
    public static function cause(Throwable $exception): Throwable
    {
        while ($exception instanceof HandlerFailedException && count($exception->getWrappedExceptions()) === 1) {
            [$exception] = array_values($exception->getWrappedExceptions());
        }

        return $exception;
    }
}
