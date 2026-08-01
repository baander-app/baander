<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Infrastructure\Adapter\OAuth\DpopTokenResponse;
use DateInterval;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * Factory for creating the league/oauth2-server AuthorizationServer instance.
 *
 * This service is registered in the Symfony DI container and injects all
 * required repository interfaces and key paths.
 */
final class AuthorizationServerFactory
{
    private readonly DateInterval $accessTokenTTL;
    private readonly DateInterval $refreshTokenTTL;
    private readonly DateInterval $authCodeTTL;
    private readonly DateInterval $deviceCodeTTL;

    public function __construct(
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly ScopeRepositoryInterface $scopeRepository,
        private readonly AuthCodeRepositoryInterface $authCodeRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly DeviceCodeRepositoryInterface $deviceCodeRepository,
        private readonly string $privateKeyPath,
        private readonly string $encryptionKey,
        private readonly string $verificationUri,
        int $accessTokenTtl = 3600,
        int $refreshTokenTtl = 2592000,
        int $authCodeTtl = 600,
        int $deviceCodeTtl = 900,
    ) {
        $this->accessTokenTTL = new DateInterval(sprintf('PT%dS', $accessTokenTtl));
        $this->refreshTokenTTL = new DateInterval(sprintf('PT%dS', $refreshTokenTtl));
        $this->authCodeTTL = new DateInterval(sprintf('PT%dS', $authCodeTtl));
        $this->deviceCodeTTL = new DateInterval(sprintf('PT%dS', $deviceCodeTtl));
    }

    public function create(): AuthorizationServer
    {
        if ($this->encryptionKey === '') {
            if (($_SERVER['APP_ENV'] ?? 'prod') === 'prod') {
                throw new \RuntimeException('AUTH_ENCRYPTION_KEY must be configured in production. Generate one with: php -r "echo \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();"');
            }

            @trigger_error(
                'No AUTH_ENCRYPTION_KEY is configured. A random key is generated per process. '
                .'This is not suitable for multi-worker setups. Set auth.encryption_key in auth.yaml.',
                E_USER_DEPRECATED,
            );
        }

        $encryptionKey = $this->encryptionKey !== ''
            ? Key::loadFromAsciiSafeString($this->encryptionKey)
            : Key::createNewRandomKey()
            ;

        $server = new AuthorizationServer(
            $this->clientRepository,
            $this->accessTokenRepository,
            $this->scopeRepository,
            $this->privateKeyPath,
            $encryptionKey,
            new DpopTokenResponse(),
        );

        // Auth Code grant with PKCE
        $authCodeGrant = new AuthCodeGrant(
            $this->authCodeRepository,
            $this->refreshTokenRepository,
            $this->authCodeTTL,
        );
        $authCodeGrant->setRefreshTokenTTL($this->refreshTokenTTL);
        $server->enableGrantType($authCodeGrant, $this->accessTokenTTL);

        // Client Credentials grant
        $clientCredentialsGrant = new ClientCredentialsGrant();
        $server->enableGrantType($clientCredentialsGrant, $this->accessTokenTTL);

        // Refresh Token grant (rotation enabled by default in league v9)
        $refreshTokenGrant = new RefreshTokenGrant($this->refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL($this->refreshTokenTTL);
        $server->enableGrantType($refreshTokenGrant, $this->accessTokenTTL);

        // Device Code grant
        $deviceCodeGrant = new DeviceCodeGrant(
            $this->deviceCodeRepository,
            $this->refreshTokenRepository,
            $this->deviceCodeTTL,
            $this->verificationUri,
        );
        $deviceCodeGrant->setRefreshTokenTTL($this->refreshTokenTTL);
        $server->enableGrantType($deviceCodeGrant, $this->accessTokenTTL);

        return $server;
    }
}
