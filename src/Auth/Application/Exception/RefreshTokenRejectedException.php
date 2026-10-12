<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Auth\Domain\Exception\TokenChainViolationException;
use RuntimeException;

/**
 * The refresh grant refused the presented token; the holder must sign in again.
 *
 * Only a verdict on the token raises it: unknown or malformed, revoked, expired,
 * replayed or out of its rotation chain, bound to another proof key or client, or
 * belonging to a disabled or deleted account. A failure to reach that verdict, such
 * as a lost database connection, is a different exception, so the endpoints can tell
 * the client to retry instead of discarding a token that may still be good. The
 * message names the reason for logs; the endpoints do not send it to the client.
 */
final class RefreshTokenRejectedException extends RuntimeException
{
    public static function notFound(): self
    {
        return new self('Refresh token not found.');
    }

    public static function revoked(): self
    {
        return new self('Refresh token has been revoked.');
    }

    public static function expired(): self
    {
        return new self('Refresh token has expired.');
    }

    public static function issuedToAnotherClient(): self
    {
        return new self('Refresh token was not issued to this client.');
    }

    public static function accountDisabled(): self
    {
        return new self('Refresh token belongs to a disabled or deleted account.');
    }

    public static function proofBindingMismatch(): self
    {
        return new self('Refresh token proof binding does not match.');
    }

    /** Another request consumed the token first. */
    public static function alreadyConsumed(): self
    {
        return new self('Refresh token reuse detected.');
    }

    public static function chainViolated(TokenChainViolationException $violation): self
    {
        return new self($violation->getMessage(), previous: $violation);
    }
}
