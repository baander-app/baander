<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Application\Exception\ClientManagementException;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gives a confidential client a new secret for the admin API and app:oauth:client:rotate-secret.
 *
 * Tokens the client already holds stay valid; the old secret stops
 * authenticating new token requests at once.
 */
final readonly class RotateClientSecretHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /** @throws ClientNotFoundException|ClientManagementException */
    #[AsMessageHandler]
    public function __invoke(RotateClientSecretCommand $command): RegisteredClientDTO
    {
        $client = $this->clientRepository->findClientByPublicId($command->clientId)
            ?? throw ClientNotFoundException::forPublicId($command->clientId->toString());

        if (!$client->getType()->isAdministered()) {
            throw ClientManagementException::protectedClient();
        }
        if (!$client->isConfidential()) {
            throw ClientManagementException::noSecret();
        }
        if ($client->isRevoked()) {
            throw ClientManagementException::revoked();
        }

        $secret = ClientSecret::generate();
        $client->rotateSecret($secret);
        $this->clientRepository->saveClient($client);

        return new RegisteredClientDTO($client, $secret->toString());
    }
}
