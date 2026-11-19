<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Registers a device, public or confidential client for the admin API and app:oauth:client:create.
 *
 * A confidential client authenticates with a generated secret, or with its own
 * signing keys when it registers as `private_key_jwt` with one key source.
 */
final readonly class RegisterClientHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /** @throws InvalidInputException with reason invalid_registration */
    #[AsMessageHandler]
    public function __invoke(RegisterClientCommand $command): RegisteredClientDTO
    {
        try {
            [$client, $secret] = $this->client($command);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), ['reason' => 'invalid_registration'], $exception);
        }

        $this->clientRepository->saveClient($client);

        return new RegisteredClientDTO($client, $secret?->toString());
    }

    /** @return array{Client, ?ClientSecret} */
    private function client(RegisterClientCommand $command): array
    {
        $type = ClientType::tryFrom($command->type);
        $method = self::authMethod($command, $type);
        $hasKeys = $command->jwks !== null || $command->jwksUri !== null;
        if ($hasKeys && $method !== ClientAuthMethod::PrivateKeyJwt) {
            throw new InvalidArgumentException('Only a private_key_jwt client has a JWK Set or a jwks_uri.');
        }

        return match ($type) {
            ClientType::Device => $command->redirectUris === []
                ? [Client::registerDevice($command->name), null]
                : throw new InvalidArgumentException('Device clients have no redirect URIs.'),
            ClientType::Public => [Client::registerPublic($command->name, $command->redirectUris), null],
            ClientType::Confidential => $method === ClientAuthMethod::PrivateKeyJwt
                ? [Client::registerConfidentialWithKeys($command->name, $command->redirectUris, ClientKeySource::fromEither($command->jwks, $command->jwksUri)), null]
                : [Client::registerConfidential($command->name, $command->redirectUris, $secret = ClientSecret::generate()), $secret],
            ClientType::FirstParty, ClientType::PersonalAccess, null => throw new InvalidArgumentException(
                'Only device, public and confidential clients can be registered.',
            ),
        };
    }

    /** The method a confidential client chose; only confidential clients choose one. */
    private static function authMethod(RegisterClientCommand $command, ?ClientType $type): ?ClientAuthMethod
    {
        if ($command->authMethod === null) {
            return $type === ClientType::Confidential ? ClientAuthMethod::ClientSecretPost : null;
        }
        if ($type !== ClientType::Confidential) {
            throw new InvalidArgumentException('Only confidential clients choose an authentication method.');
        }

        return match (ClientAuthMethod::tryFrom($command->authMethod)) {
            ClientAuthMethod::ClientSecretPost => ClientAuthMethod::ClientSecretPost,
            ClientAuthMethod::PrivateKeyJwt => ClientAuthMethod::PrivateKeyJwt,
            ClientAuthMethod::None, null => throw new InvalidArgumentException(
                'A confidential client authenticates with client_secret_post or private_key_jwt.',
            ),
        };
    }
}
