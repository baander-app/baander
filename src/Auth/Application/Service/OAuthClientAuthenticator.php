<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Model\OAuth\ClientType;
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
 *
 * Baander's first-party client is never an OAuth client: its apps sign in through password and
 * passkey login and refresh at the first-party endpoint. On the back channel it authenticates by
 * its ID and is then refused with unauthorized_client; on the consent page's front channel it is
 * refused with invalid_client, which is never redirected. A signed-in first-party session still
 * decides on other clients' requests, because those endpoints authenticate the user, not a client.
 */
final readonly class OAuthClientAuthenticator
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ClientAssertionVerifierInterface $assertionVerifier,
    ) {
    }

    /**
     * @throws OAuthProtocolException invalid_client, or unauthorized_client for the first-party client
     * @throws DpopStoreUnavailableException when an assertion's single use cannot be checked
     */
    public function authenticate(ClientCredentials $credentials): Client
    {
        $client = $this->find($credentials->clientId);

        $authenticated = match ($client->getAuthMethod()) {
            ClientAuthMethod::None => !$credentials->presentsSecret() && !$credentials->presentsAssertion(),
            // The stored digest is compared in constant time (ClientSecret::matches).
            ClientAuthMethod::ClientSecretPost => !$credentials->presentsAssertion() && $client->authenticatesWith($credentials->presentedSecret()),
            ClientAuthMethod::PrivateKeyJwt => !$credentials->presentsSecret() && $this->verifiesAssertion($credentials, $client),
        };
        if (!$authenticated) {
            throw OAuthProtocolException::invalidClient();
        }
        if (self::isFirstParty($client)) {
            throw OAuthProtocolException::unauthorizedClient('The first-party client signs in and refreshes through its own endpoints.');
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
     * @throws OAuthProtocolException invalid_client, also for the first-party client
     */
    public function identify(string $clientId): Client
    {
        $client = $this->find($clientId);
        if (self::isFirstParty($client)) {
            throw OAuthProtocolException::invalidClient('The first-party client makes no authorization requests.');
        }

        return $client;
    }

    private static function isFirstParty(Client $client): bool
    {
        return $client->getType() === ClientType::FirstParty;
    }

    /**
     * The usable client with this public ID.
     *
     * @throws OAuthProtocolException invalid_client for an unknown, malformed or revoked client
     */
    private function find(string $clientId): Client
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
