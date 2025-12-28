<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Grants;

use App\Models\User as EloquentUser;
use App\Modules\Auth\OAuth\Entities\UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Custom grant type for creating tokens for pre-authenticated users
 *
 * This grant is used internally when a user has already been authenticated
 * via password, passkey, or other means, and we just need to issue tokens.
 */
class PreAuthenticatedGrant extends AbstractGrant
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected RefreshTokenRepositoryInterface   $refreshTokenRepository,
    )
    {
    }

    /**
     * @throws OAuthServerException
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface  $responseType,
        \DateInterval          $accessTokenTTL,
    ): ResponseTypeInterface
    {
        $clientId = $this->getRequestParameter('client_id', $request);
        $clientSecret = $this->getRequestParameter('client_secret', $request);
        $userId = $this->getRequestParameter('user_id', $request);

        if ($clientId === null) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        if ($clientSecret === null) {
            throw OAuthServerException::invalidRequest('client_secret');
        }

        if ($userId === null) {
            throw OAuthServerException::invalidRequest('user_id');
        }

        // Validate client
        $client = $this->clientRepository->getClientEntity($clientId, $this->getIdentifier(), $clientSecret, false);

        if (!$client instanceof ClientEntityInterface) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidClient($request);
        }

        // Validate user exists
        $eloquentUser = EloquentUser::find($userId);
        if (!$eloquentUser) {
            throw OAuthServerException::invalidRequest('user_id', 'User not found');
        }

        // Create user entity without password validation (already authenticated)
        $userEntity = new UserEntity();
        $userEntity->setIdentifier((string)$eloquentUser->id);
        $userEntity->setAttribute('email', $eloquentUser->email);
        $userEntity->setAttribute('name', $eloquentUser->name);

        // Validate scopes
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request), $client->getRedirectUri());

        // Issue access token
        $accessToken = $this->issueAccessToken(
            $accessTokenTTL,
            $client,
            $userEntity->getIdentifier(),
            $scopes,
        );

        // Issue refresh token if enabled
        $refreshToken = $this->issueRefreshToken($accessToken);

        // Inject tokens into response
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        return $responseType;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'pre_authenticated';
    }
}
