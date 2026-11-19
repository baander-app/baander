<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ReplaceClientKeysCommand;
use App\Auth\Application\Service\AdministeredClients;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Replaces a `private_key_jwt` client's inline JWK Set or `jwks_uri`, for the admin API
 * and app:oauth:client:replace-keys.
 *
 * Assertions signed with the old keys stop authenticating at once; tokens the client
 * already holds stay valid. Replacing the keys advances the client's updated time,
 * which retires any key set fetched from the old source.
 */
final readonly class ReplaceClientKeysHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException with reason protected_client, no_keys or revoked
     * @throws InvalidInputException with reason invalid_keys
     */
    #[AsMessageHandler]
    public function __invoke(ReplaceClientKeysCommand $command): Client
    {
        $client = AdministeredClients::find($this->clientRepository, $command->clientId);

        if ($client->getAuthMethod() !== ClientAuthMethod::PrivateKeyJwt) {
            throw AdministeredClients::conflict('no_keys', 'Only clients that authenticate with private_key_jwt have keys.');
        }
        if ($client->isRevoked()) {
            throw AdministeredClients::conflict('revoked', 'The client has been revoked.');
        }

        try {
            $keys = ClientKeySource::fromEither($command->jwks, $command->jwksUri);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), ['reason' => 'invalid_keys'], $exception);
        }

        $client->replaceKeys($keys);
        $this->clientRepository->saveClient($client);

        return $client;
    }
}
