<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Auth\Domain\Exception\TokenChainViolationException;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use RuntimeException;
use Throwable;

/**
 * The refresh grant refused the presented token; the holder must sign in again.
 *
 * Only a verdict on the token raises it: unknown or malformed, revoked, expired,
 * replayed or out of its rotation chain, bound to another proof key or client, or
 * belonging to a disabled or deleted account. A failure to reach that verdict, such
 * as a lost database connection, is a different exception, so the endpoints can tell
 * the client to retry instead of discarding a token that may still be good. The
 * message names the reason for logs; the endpoints do not send it to the client.
 *
 * A reuse verdict names the chain to revoke and the reason for the log. The handler
 * revokes that chain in a transaction of its own, after the issuing transaction rolled
 * back and before the rejection is answered.
 */
final class RefreshTokenRejectedException extends RuntimeException
{
    public const string REASON_REPLACEMENT_USED = 'replacement used';
    public const string REASON_OUTSIDE_WINDOW = 'outside window';
    public const string REASON_SUPERSEDED_TOKEN_PRESENTED = 'superseded token presented';

    private function __construct(
        string $message,
        public readonly ?ChainId $chainToRevoke = null,
        public readonly ?string $reuseReason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

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

    /** A used token was presented again while a replacement of it had already been used. */
    public static function replacementUsed(ChainId $chainId): self
    {
        return new self('Refresh token reuse detected: a replacement was already used.', $chainId, self::REASON_REPLACEMENT_USED);
    }

    /** A used token was presented again after the lost-response grace window closed. */
    public static function outsideGraceWindow(ChainId $chainId): self
    {
        return new self('Refresh token reuse detected: presented again outside the grace window.', $chainId, self::REASON_OUTSIDE_WINDOW);
    }

    /** A token a grace superseded was presented while its chain still holds a live token. */
    public static function supersededTokenPresented(ChainId $chainId): self
    {
        return new self('Refresh token reuse detected: a superseded token was presented.', $chainId, self::REASON_SUPERSEDED_TOKEN_PRESENTED);
    }

    /**
     * A concurrent presentation of the same token consumed it, and a competing grace left
     * no live replacement to supersede; the chain stays as it is.
     */
    public static function concurrentLoser(): self
    {
        return new self('Refresh token was consumed by a concurrent request.');
    }

    public static function chainViolated(TokenChainViolationException $violation): self
    {
        return new self($violation->getMessage(), previous: $violation);
    }
}
