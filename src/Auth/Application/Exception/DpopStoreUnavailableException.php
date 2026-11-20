<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\RetryableFault;
use RuntimeException;
use Throwable;

/**
 * The DPoP nonce or jti store, the client assertion jti store, or a client's published
 * key set did not answer, so no proof or assertion can be judged.
 *
 * Only a failure to reach the store or the key set raises it: a refused or dropped
 * connection, a read that timed out, an exhausted connection pool, or a key set fetch
 * that failed or was refused. A nonce that is missing, a jti that was seen before and
 * a key set without the assertion's key are verdicts and keep their boolean answers.
 * Every endpoint answers this exception with 503 and `Retry-After`, after
 * RETRY_AFTER_SECONDS; the checks stay fail-closed, since no proof is accepted without
 * the replay check.
 */
final class DpopStoreUnavailableException extends RuntimeException implements RetryableFault
{
    /** The message of the 503 body at the edges that render their own error envelope. */
    public const string CLIENT_MESSAGE = 'The authorization server is temporarily unavailable; retry after the Retry-After delay.';

    public static function nonceStore(Throwable $cause): self
    {
        return new self('The DPoP nonce store is unavailable.', previous: $cause);
    }

    public static function jtiStore(Throwable $cause): self
    {
        return new self('The DPoP jti store is unavailable.', previous: $cause);
    }

    public static function assertionJtiStore(Throwable $cause): self
    {
        return new self('The client assertion jti store is unavailable.', previous: $cause);
    }

    public static function clientKeySetLock(Throwable $cause): self
    {
        return new self('The client key set fetch lock is unavailable.', previous: $cause);
    }

    public static function clientKeySetFetch(): self
    {
        return new self('The client key set could not be fetched.');
    }
}
