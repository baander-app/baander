<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use Throwable;

/**
 * A store or service the request depends on did not answer, so nothing about the request
 * was decided: the client keeps what it presented and sends the same request again after
 * the delay. HTTP answers it with 503, `Retry-After` and `Cache-Control: no-store` at
 * whichever endpoint it reaches.
 */
interface RetryableFault extends Throwable
{
    /** How long a client waits before presenting the same request again. */
    public const int RETRY_AFTER_SECONDS = 5;
}
