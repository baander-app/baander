<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RevokeClientCommand;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Revokes a user's client and every token issued to it.
 *
 * The resource server checks each access token's own revocation flag, so the
 * client flag alone would leave issued tokens usable until they expire.
 */
final readonly class RevokeClientHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    /** @throws ClientNotFoundException The client is unknown or owned by someone else. */
    #[AsMessageHandler]
    public function __invoke(RevokeClientCommand $command): void
    {
        $client = $this->clientRepository->findClientByPublicId($command->clientPublicId);
        if ($client === null || !$client->isOwnedBy($command->userId)) {
            throw ClientNotFoundException::forOwner();
        }

        $client->revoke();

        $this->transaction->run(function () use ($client): void {
            $this->clientRepository->saveClient($client);
            $this->refreshTokenRepository->revokeByClientId($client->getId());
            $this->accessTokenRepository->revokeByClientId($client->getId());
        });
    }
}
