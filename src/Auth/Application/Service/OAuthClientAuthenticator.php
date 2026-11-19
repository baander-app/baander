<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;

/**
 * Identifies and authenticates the client of an OAuth request by its public ID.
 *
 * Each client authenticates by the one method it was registered with: public clients
 * with their ID alone (`none`), confidential clients with their secret or with a JWT
 * assertion signed by one of their keys (`private_key_jwt`). A secret client presents
 * its secret in the body (`client_secret_post`) or in HTTP Basic (`client_secret_basic`,
 * which RFC 6749 section 2.3.1 requires every server to accept). A request that
 * presents any other method, or more than one, such as a secret both in HTTP Basic
 * and in the body, fails (RFC 6749 section 2.3). Unknown, malformed and revoked clients fail alike, so
 * the response does not reveal which it was.
 */
final readonly class OAuthClientAuthenticator
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ClientAssertionVerifierInterface $assertionVerifier,
    ) {
    }

    /**
     * @throws OAuthProtocolException invalid_client
     * @throws DpopStoreUnavailableException when an assertion's single use cannot be checked
     */
    public function authenticate(ClientCredentials $credentials): Client
    {
        $client = $this->identify($credentials->clientId);

        $authenticated = match ($client->getAuthMethod()) {
            ClientAuthMethod::None => !$credentials->presentsSecret() && !$credentials->presentsAssertion(),
            // The stored digest is compared in constant time (ClientSecret::matches).
            ClientAuthMethod::ClientSecretPost => !$credentials->presentsAssertion() && $client->authenticatesWith($credentials->presentedSecret()),
            ClientAuthMethod::PrivateKeyJwt => !$credentials->presentsSecret() && $this->verifiesAssertion($credentials, $client),
        };
        if (!$authenticated) {
            throw OAuthProtocolException::invalidClient();
        }

        return $client;
    }

    private function verifiesAssertion(ClientCredentials $credentials, Client $client): bool
    {
        $assertion = $credentials->jwtBearerAssertion();

        return $assertion !== null && $this->assertionVerifier->verify($assertion, $client);
    }

    /**
     * Resolves a client named in a front-channel request, which carries no secret.
     *
     * @throws OAuthProtocolException invalid_client
     */
    public function identify(string $clientId): Client
    {
        try {
            $publicId = PublicId::fromString($clientId);
        } catch (InvalidArgumentException) {
            throw OAuthProtocolException::invalidClient();
        }

        $client = $this->clientRepository->findClientByPublicId($publicId);
        if ($client === null || $client->isRevoked()) {
            throw OAuthProtocolException::invalidClient();
        }

        return $client;
    }
}
