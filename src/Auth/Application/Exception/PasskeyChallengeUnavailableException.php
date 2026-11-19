<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

/**
 * The passkey challenge store could not answer. Not a failed ceremony: the caller
 * answers 503 and the client retries.
 */
final class PasskeyChallengeUnavailableException extends \RuntimeException
{
    public const int RETRY_AFTER_SECONDS = 1;
}
