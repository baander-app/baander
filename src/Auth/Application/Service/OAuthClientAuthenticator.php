<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;

/**
 * Identifies and authenticates the client of an OAuth request by its public ID.
 *
 * Public clients authenticate with their ID alone (`none`); confidential clients
 * must also send their secret (`client_secret_post`). Unknown, malformed and
 * revoked clients fail alike, so the response does not reveal which it was.
 */
final readonly class OAuthClientAuthenticator
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /** @throws OAuthProtocolException invalid_client */
    public function authenticate(string $clientId, ?string $clientSecret = null): Client
    {
        $client = $this->identify($clientId);

        // The stored digest is compared in constant time (ClientSecret::matches).
        if ($client->isConfidential() && !$client->authenticatesWith($clientSecret)) {
            throw OAuthProtocolException::invalidClient();
        }

        return $client;
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
