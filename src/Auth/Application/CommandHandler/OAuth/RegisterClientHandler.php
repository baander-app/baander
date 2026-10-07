<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Application\Exception\ClientManagementException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Registers a device, public or confidential client for the admin API and app:oauth:client:create.
 */
final readonly class RegisterClientHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /** @throws ClientManagementException invalid_registration */
    #[AsMessageHandler]
    public function __invoke(RegisterClientCommand $command): RegisteredClientDTO
    {
        $secret = null;

        try {
            $client = match (ClientType::tryFrom($command->type)) {
                ClientType::Device => $command->redirectUris === []
                    ? Client::registerDevice($command->name)
                    : throw new InvalidArgumentException('Device clients have no redirect URIs.'),
                ClientType::Public => Client::registerPublic($command->name, $command->redirectUris),
                ClientType::Confidential => Client::registerConfidential(
                    $command->name,
                    $command->redirectUris,
                    $secret = ClientSecret::generate(),
                ),
                ClientType::FirstParty, ClientType::PersonalAccess, null => throw new InvalidArgumentException(
                    'Only device, public and confidential clients can be registered.',
                ),
            };
        } catch (InvalidArgumentException $exception) {
            throw ClientManagementException::invalidRegistration($exception->getMessage());
        }

        $this->clientRepository->saveClient($client);

        return new RegisteredClientDTO($client, $secret?->toString());
    }
}
