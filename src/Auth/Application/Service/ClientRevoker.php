<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;

/**
 * Revokes a client and every token issued to it, in one transaction.
 *
 * The resource server checks each access token's own revocation flag, so the
 * client flag alone would leave issued tokens usable until they expire. The
 * client row is updated first: its row lock makes a concurrent issuance, which
 * holds the row FOR SHARE, either finish before the token updates run or see
 * the revoked flag and fail.
 */
final readonly class ClientRevoker
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    public function revoke(Client $client): void
    {
        $client->revoke();

        $this->transaction->run(function () use ($client): void {
            $this->clientRepository->saveClient($client);
            $this->refreshTokenRepository->revokeByClientId($client->getId());
            $this->accessTokenRepository->revokeByClientId($client->getId());
        });
    }
}
