<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;

/**
 * Factory for creating the league/oauth2-server ResourceServer instance.
 *
 * The ResourceServer validates Bearer tokens on API requests and extracts
 * the token attributes (user identifier, scopes, client, etc.) into the
 * PSR-7 request object.
 */
final class ResourceServerFactory
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly string $publicKeyPath,
        private readonly ?string $resourceServerUri = null,
    ) {
    }

    public function create(): ResourceServer
    {
        $validator = new DpopAwareBearerTokenValidator(
            $this->accessTokenRepository,
            null,
            $this->resourceServerUri,
        );

        return new ResourceServer(
            $this->accessTokenRepository,
            $this->publicKeyPath,
            $validator,
        );
    }
}
