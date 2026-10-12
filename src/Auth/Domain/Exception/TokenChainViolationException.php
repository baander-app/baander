<?php

declare(strict_types=1);

namespace App\Auth\Domain\Exception;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use RuntimeException;

/** A refresh token broke its rotation chain: it was replayed, or its predecessor is not a rotated, live link. */
final class TokenChainViolationException extends RuntimeException
{
    /** A used token was presented again; the handler decides whether the chain is revoked. */
    public static function replayed(ChainId $chainId): self
    {
        return new self(sprintf(
            'Refresh token reuse detected on chain %s.',
            $chainId->toString(),
        ));
    }

    public static function brokenLink(string $violation): self
    {
        return new self('Refresh token chain integrity violation: ' . $violation);
    }
}
