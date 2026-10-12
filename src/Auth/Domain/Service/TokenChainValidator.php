<?php

declare(strict_types=1);

namespace App\Auth\Domain\Service;

use App\Auth\Domain\Exception\TokenChainViolationException;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;

/**
 * Domain service for validating refresh token chain integrity.
 *
 * Enforces refresh token rotation security: each refresh token may be used
 * exactly once. A previously-used token presented again is refused here; whether
 * that presentation is a lost-response retry or a replay that ends the chain is
 * decided by the refresh handler on rows read under the row lock.
 */
final class TokenChainValidator
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
    ) {
    }

    /**
     * Revoke all tokens in a chain (replay attack response).
     */
    public function revokeChain(ChainId $chainId): void
    {
        $this->accessTokenRepository->revokeByChainId($chainId);
        $this->refreshTokenRepository->revokeByChainId($chainId);
    }

    /**
     * Validate a refresh token against its predecessor for rotation security.
     *
     * Rules:
     * 1. The incoming token must not have been used before (hasBeenUsed check).
     * 2. If a previousRefreshToken is set, that predecessor must have been used
     *    (meaning it was already rotated) but not revoked.
     * 3. If the predecessor has NOT been used, this means the current token was
     *    never properly issued -- reject it.
     *
     * Nothing is revoked here: the refresh handler decides under the row lock.
     *
     * @throws TokenChainViolationException on chain integrity violation
     */
    public function validate(RefreshToken $incoming, ?RefreshToken $previous): void
    {
        // Rule 1: If the incoming token was already used, it is refused.
        if ($incoming->hasBeenUsed()) {
            throw TokenChainViolationException::replayed($incoming->getChainId());
        }

        if ($previous === null) {
            // First token in chain -- no predecessor to validate against.
            return;
        }

        // Rule 2: The predecessor must have been used (i.e., previously rotated).
        if (!$previous->hasBeenUsed()) {
            throw TokenChainViolationException::brokenLink('the previous refresh token has not been rotated.');
        }

        // Rule 3: The predecessor must not be revoked.
        if ($previous->isRevoked()) {
            throw TokenChainViolationException::brokenLink('the previous refresh token has been revoked.');
        }

        // Additional check: ensure both tokens belong to the same chain.
        if (!$incoming->getChainId()->equals($previous->getChainId())) {
            throw TokenChainViolationException::brokenLink('chain ID mismatch.');
        }
    }

    /**
     * Convenience method: validate an incoming refresh token by loading its
     * predecessor from the repository and performing chain validation.
     *
     * @throws TokenChainViolationException on chain integrity violation
     */
    public function validateWithLoadedPrevious(RefreshToken $incoming): void
    {
        $previous = $incoming->getPreviousRefreshToken();
        $this->validate($incoming, $previous);
    }
}
