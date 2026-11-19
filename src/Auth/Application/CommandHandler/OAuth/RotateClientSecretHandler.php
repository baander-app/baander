<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Application\Service\AdministeredClients;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gives a client that authenticates with a secret a new one, for the admin API and
 * app:oauth:client:rotate-secret.
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

    /** @throws NotFoundException|ConflictException with reason protected_client, no_secret or revoked */
    #[AsMessageHandler]
    public function __invoke(RotateClientSecretCommand $command): RegisteredClientDTO
    {
        $client = AdministeredClients::find($this->clientRepository, $command->clientId);

        if ($client->getAuthMethod() !== ClientAuthMethod::ClientSecretPost) {
            throw AdministeredClients::conflict('no_secret', 'Only clients that authenticate with a secret have one.');
        }
        if ($client->isRevoked()) {
            throw AdministeredClients::conflict('revoked', 'The client has been revoked.');
        }

        $secret = ClientSecret::generate();
        $client->rotateSecret($secret);
        $this->clientRepository->saveClient($client);

        return new RegisteredClientDTO($client, $secret->toString());
    }
}
