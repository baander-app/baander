<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;

/**
 * Ends a token chain: every refresh and access token of it is revoked in one transaction,
 * and the WebSocket connections its access tokens opened are closed after the commit.
 *
 * Closing earlier would let a reconnect pass on a token the commit has not yet revoked.
 */
final readonly class TokenChainRevoker
{
    public function __construct(
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private TransactionPortInterface $transaction,
        private ChainConnectionCloser $connectionCloser,
    ) {
    }

    public function revoke(ChainId $chainId): void
    {
        $accessTokenIds = $this->transaction->run(function () use ($chainId): array {
            // Lock order of every chain revocation: refresh rows before access rows. A
            // refresh of the same chain locks its refresh row first, so the two never
            // wait on each other in opposite order.
            $this->refreshTokenRepository->revokeByChainId($chainId);

            // The ids are listed under the refresh-row locks, before the access rows are
            // revoked: a rotation that committed a replacement pair while this revocation
            // waited is in the list, so its socket is closed too.
            $accessTokenIds = $this->accessTokenRepository->findTokenIdsByChainId($chainId);
            $this->accessTokenRepository->revokeByChainId($chainId);

            return $accessTokenIds;
        });

        $this->connectionCloser->close($accessTokenIds, $chainId);
    }
}
