<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Exception\ClientManagementException;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Application\Service\ClientRevoker;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Revokes a device, public or confidential client and its tokens, for the admin API and app:oauth:client:revoke.
 *
 * Revoking an already revoked client succeeds again, so a retried request is harmless.
 */
final readonly class RevokeRegisteredClientHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ClientRevoker $clientRevoker,
    ) {
    }

    /** @throws ClientNotFoundException|ClientManagementException */
    #[AsMessageHandler]
    public function __invoke(RevokeRegisteredClientCommand $command): Client
    {
        $client = $this->clientRepository->findClientByPublicId($command->clientId)
            ?? throw ClientNotFoundException::forPublicId($command->clientId->toString());

        if (!$client->getType()->isAdministered()) {
            throw ClientManagementException::protectedClient();
        }

        $this->clientRevoker->revoke($client);

        return $client;
    }
}
