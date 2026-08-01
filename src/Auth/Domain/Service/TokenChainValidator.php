<?php

declare(strict_types=1);

namespace App\Auth\Domain\Service;

use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use RuntimeException;

/**
 * Domain service for validating refresh token chain integrity.
 *
 * Enforces refresh token rotation security: each refresh token may be used
 * exactly once. If a previously-used token is presented again, this signals
 * a replay attack and the entire chain is revoked.
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
     * 4. If the predecessor was used and the incoming token has also been used,
     *    this is a replay -- revoke the chain.
     *
     * @throws RuntimeException on chain integrity violation
     */
    public function validate(RefreshToken $incoming, ?RefreshToken $previous): void
    {
        // Rule 1: If the incoming token was already used, this is a replay.
        if ($incoming->hasBeenUsed()) {
            $this->revokeChain($incoming->getChainId());

            throw new RuntimeException(
                sprintf(
                    'Refresh token reuse detected. The entire token chain (chain: %s) has been revoked.',
                    $incoming->getChainId()->toString(),
                ),
            );
        }

        if ($previous === null) {
            // First token in chain -- no predecessor to validate against.
            return;
        }

        // Rule 2: The predecessor must have been used (i.e., previously rotated).
        if (!$previous->hasBeenUsed()) {
            throw new RuntimeException(
                'Refresh token chain integrity violation: the previous refresh token has not been rotated.',
            );
        }

        // Rule 3: The predecessor must not be revoked.
        if ($previous->isRevoked()) {
            throw new RuntimeException(
                'Refresh token chain integrity violation: the previous refresh token has been revoked.',
            );
        }

        // Additional check: ensure both tokens belong to the same chain.
        if (!$incoming->getChainId()->equals($previous->getChainId())) {
            throw new RuntimeException(
                'Refresh token chain integrity violation: chain ID mismatch.',
            );
        }
    }

    /**
     * Convenience method: validate an incoming refresh token by loading its
     * predecessor from the repository and performing chain validation.
     *
     * @throws RuntimeException on chain integrity violation
     */
    public function validateWithLoadedPrevious(RefreshToken $incoming): void
    {
        $previous = $incoming->getPreviousRefreshToken();
        $this->validate($incoming, $previous);
    }
}
