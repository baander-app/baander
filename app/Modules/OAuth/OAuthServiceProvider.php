<?php

declare(strict_types=1);

namespace App\Modules\OAuth;

use App\Modules\OAuth\Contracts\AccessTokenRepositoryInterface;
use App\Modules\OAuth\Contracts\AuthCodeRepositoryInterface;
use App\Modules\OAuth\Contracts\ClientRepositoryInterface;
use App\Modules\OAuth\Contracts\RefreshTokenRepositoryInterface;
use App\Modules\OAuth\Contracts\ScopeRepositoryInterface;
use App\Modules\OAuth\Contracts\UserRepositoryInterface;
use App\Modules\OAuth\Grants\DeviceCodeGrant;
use App\Modules\OAuth\Repositories\AccessTokenRepository;
use App\Modules\OAuth\Repositories\AuthCodeRepository;
use App\Modules\OAuth\Repositories\ClientRepository;
use App\Modules\OAuth\Repositories\RefreshTokenRepository;
use App\Modules\OAuth\Repositories\ScopeRepository;
use App\Modules\OAuth\Repositories\UserRepository;
use DateInterval;
use Illuminate\Support\ServiceProvider;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositories();
        $this->registerOAuthServer();
    }

    private function registerRepositories(): void
    {
        $this->app->bind(ClientRepositoryInterface::class, ClientRepository::class);
        $this->app->bind(AccessTokenRepositoryInterface::class, AccessTokenRepository::class);
        $this->app->bind(ScopeRepositoryInterface::class, ScopeRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RefreshTokenRepositoryInterface::class, RefreshTokenRepository::class);
        $this->app->bind(AuthCodeRepositoryInterface::class, AuthCodeRepository::class);
    }

    private function registerOAuthServer(): void
    {
        $this->app->singleton(AuthorizationServer::class, function () {
            $privateKey = config('oauth.private_key', storage_path('oauth-private.key'));

            $server = new AuthorizationServer(
                $this->app->make(ClientRepositoryInterface::class),
                $this->app->make(AccessTokenRepositoryInterface::class),
                $this->app->make(ScopeRepositoryInterface::class),
                $privateKey,
                config('oauth.encryption_key', config('app.key'))
            );

            // Enable grants
            $this->enableGrants($server);

            return $server;
        });

        $this->app->singleton(ResourceServer::class, function () {
            $publicKey = config('oauth.public_key', storage_path('oauth-public.key'));

            return new ResourceServer(
                $this->app->make(AccessTokenRepositoryInterface::class),
                $publicKey
            );
        });
    }

    private function enableGrants(AuthorizationServer $server): void
    {
        $accessTokenTTL = new DateInterval(config('oauth.access_token_ttl', 'PT1H'));
        $refreshTokenTTL = new DateInterval(config('oauth.refresh_token_ttl', 'P1M'));
        $authCodeTTL = new DateInterval(config('oauth.auth_code_ttl', 'PT10M'));

        // Authorization Code Grant
        $authCodeGrant = new AuthCodeGrant(
            $this->app->make(AuthCodeRepositoryInterface::class),
            $this->app->make(RefreshTokenRepositoryInterface::class),
            $authCodeTTL
        );
        $authCodeGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($authCodeGrant, $accessTokenTTL);

        // Password Grant
        $passwordGrant = new PasswordGrant(
            $this->app->make(UserRepositoryInterface::class),
            $this->app->make(RefreshTokenRepositoryInterface::class)
        );
        $passwordGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($passwordGrant, $accessTokenTTL);

        // Client Credentials Grant
        $server->enableGrantType(new ClientCredentialsGrant(), $accessTokenTTL);

        // Refresh Token Grant
        $refreshTokenGrant = new RefreshTokenGrant($this->app->make(RefreshTokenRepositoryInterface::class));
        $refreshTokenGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($refreshTokenGrant, $accessTokenTTL);

        // Device Code Grant
        $deviceCodeGrant = new DeviceCodeGrant($this->app->make(RefreshTokenRepositoryInterface::class));
        $server->enableGrantType($deviceCodeGrant, $accessTokenTTL);
    }
}
