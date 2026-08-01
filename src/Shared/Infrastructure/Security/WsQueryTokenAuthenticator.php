<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates WebSocket handshake requests using an OAuth 2.0 token
 * passed as a query parameter.
 *
 * Authenticates WebSocket connections via query token.
 * authenticator. It is invoked directly by the WithWebSocketHandler
 * configurator's authenticator closure during the Swoole onHandshake callback.
 */
final class WsQueryTokenAuthenticator
{
    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly UserRepositoryInterface $userRepository,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
    ) {}

    /**
     * Authenticates a WebSocket handshake request.
     * Returns the authenticated user's UUID string, or null on failure.
     */
    public function authenticate(\Swoole\Http\Request $request): ?string
    {
        $token = $request->get['token'] ?? null;

        if ($token === null || $token === '') {
            return null;
        }

        // Build a minimal Symfony Request from the Swoole request so
        // psrHttpFactory->createRequest() can produce a PSR-7 object.
        $symfonyRequest = Request::create(
            $request->server['request_uri'] ?? '/api/ws',
            'GET',
            ['token' => $token],
        );

        $psrRequest = $this->psrHttpFactory->createRequest($symfonyRequest)
            ->withHeader('Authorization', sprintf('Bearer %s', $token));

        try {
            $validatedRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException) {
            return null;
        }

        $userIdentifier = $validatedRequest->getAttribute('oauth_user_id');

        if ($userIdentifier === null || $userIdentifier === '') {
            return null;
        }

        $user = $this->userRepository->findByUuid(Uuid::fromString($userIdentifier));

        if ($user === null) {
            return null;
        }

        return $user->getId()->toString();
    }
}
