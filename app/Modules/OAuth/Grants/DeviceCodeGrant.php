<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Grants;

use App\Models\OAuth\DeviceCode;
use DateInterval;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

class DeviceCodeGrant extends AbstractGrant
{
    protected $refreshTokenRepository;

    public function __construct(RefreshTokenRepositoryInterface $refreshTokenRepository)
    {
        $this->refreshTokenRepository = $refreshTokenRepository;
        $this->setRefreshTokenRepository($refreshTokenRepository);
    }

    public function getIdentifier(): string
    {
        return 'urn:ietf:params:oauth:grant-type:device_code';
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ): ResponseTypeInterface {
        list($clientId) = $this->getClientCredentials($request);

        $client = $this->getClientEntityOrFail($clientId, $request);

        $bodyParams = (array) $request->getParsedBody();
        $deviceCode = $this->getRequestParameter('device_code', $request, null);

        if ($deviceCode === null) {
            throw OAuthServerException::invalidRequest('device_code');
        }

        // Find the device code in database
        $deviceCodeModel = DeviceCode::where('device_code', $deviceCode)
            ->where('client_id', $client->getIdentifier())
            ->first();

        if (!$deviceCodeModel) {
            throw OAuthServerException::invalidGrant();
        }

        if ($deviceCodeModel->isExpired()) {
            throw OAuthServerException::invalidGrant();
        }

        // Check polling interval
        if ($deviceCodeModel->last_polled_at &&
            $deviceCodeModel->last_polled_at->addSeconds($deviceCodeModel->interval)->isFuture()) {
            throw OAuthServerException::slowDown();
        }

        $deviceCodeModel->updateLastPolled();

        // Check if user denied
        if ($deviceCodeModel->denied) {
            throw OAuthServerException::accessDenied();
        }

        // Check if still pending
        if (!$deviceCodeModel->approved) {
            throw OAuthServerException::authorizationPending();
        }

        // Device code is approved, create access token
        $scopes = $this->validateDeviceCodeScopes($deviceCodeModel->scopes ?? []);
        $scopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $deviceCodeModel->user_id);

        // Issue and persist access token
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $deviceCodeModel->user_id, $scopes);
        $this->getEmitter()->emit(new RequestEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request));
        $responseType->setAccessToken($accessToken);

        // Issue refresh token
        if ($this->isRefreshTokenEnabled()) {
            $refreshToken = $this->issueRefreshToken($accessToken);
            if ($refreshToken !== null) {
                $this->getEmitter()->emit(new RequestEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request));
                $responseType->setRefreshToken($refreshToken);
            }
        }

        // Clean up the device code
        $deviceCodeModel->delete();

        return $responseType;
    }

    public function canRespondToAccessTokenRequest(ServerRequestInterface $request): bool
    {
        $bodyParams = (array) $request->getParsedBody();

        return array_key_exists('grant_type', $bodyParams)
            && $bodyParams['grant_type'] === $this->getIdentifier()
            && array_key_exists('device_code', $bodyParams);
    }

    private function validateDeviceCodeScopes(array $scopes): array
    {
        $validScopes = [];

        foreach ($scopes as $scopeItem) {
            $scope = $this->scopeRepository->getScopeEntityByIdentifier($scopeItem);
            if ($scope instanceof \League\OAuth2\Server\Entities\ScopeEntityInterface) {
                $validScopes[] = $scope;
            }
        }

        return $validScopes;
    }
}
