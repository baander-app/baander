<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth;

use App\Modules\Auth\OAuth\Contracts\{ClientRepositoryInterface};
use App\Modules\Auth\OAuth\Contracts\AccessTokenRepositoryInterface;
use App\Modules\Auth\OAuth\Contracts\AuthCodeRepositoryInterface;
use App\Modules\Auth\OAuth\Contracts\DeviceCodeRepositoryInterface;
use App\Modules\Auth\OAuth\Contracts\RefreshTokenRepositoryInterface;
use App\Modules\Auth\OAuth\Contracts\ScopeRepositoryInterface;
use App\Modules\Auth\OAuth\Contracts\UserRepositoryInterface;
use App\Modules\Auth\OAuth\Grants\PreAuthenticatedGrant;
use App\Modules\Auth\OAuth\Repositories\{RefreshTokenRepository};
use App\Modules\Auth\OAuth\Repositories\AccessTokenRepository;
use App\Modules\Auth\OAuth\Repositories\AuthCodeRepository;
use App\Modules\Auth\OAuth\Repositories\ClientRepository;
use App\Modules\Auth\OAuth\Repositories\DeviceCodeRepository;
use App\Modules\Auth\OAuth\Repositories\ScopeRepository;
use App\Modules\Auth\OAuth\Repositories\UserRepository;
use DateInterval;
use DateMalformedIntervalStringException;
use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\ServiceProvider;
use League\OAuth2\Server\{AuthorizationServer,
    CryptKey,
    Grant\AuthCodeGrant,
    Grant\ClientCredentialsGrant,
    Grant\DeviceCodeGrant,
    Grant\PasswordGrant,
    Grant\RefreshTokenGrant,
    ResourceServer};

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositories();
        $this->registerOAuthServer();
    }

    private function registerRepositories(): void
    {
        $this->app->scoped(AccessTokenRepositoryInterface::class, AccessTokenRepository::class);
        $this->app->scoped(AuthCodeRepositoryInterface::class, AuthCodeRepository::class);
        $this->app->scoped(ClientRepositoryInterface::class, ClientRepository::class);
        $this->app->scoped(DeviceCodeRepositoryInterface::class, DeviceCodeRepository::class);
        $this->app->scoped(RefreshTokenRepositoryInterface::class, RefreshTokenRepository::class);
        $this->app->scoped(ScopeRepositoryInterface::class, ScopeRepository::class);
        $this->app->scoped(UserRepositoryInterface::class, UserRepository::class);
    }

    private function registerOAuthServer(): void
    {
        $this->app->bind(AuthorizationServer::class, function () {
            $privateKeyPath = config('oauth.private_key');
            $keyPassphrase = config('oauth.encryption_key');

            $privateKey = new CryptKey($privateKeyPath, $keyPassphrase);

            $server = new AuthorizationServer(
                $this->app->make(ClientRepositoryInterface::class),
                $this->app->make(AccessTokenRepositoryInterface::class),
                $this->app->make(ScopeRepositoryInterface::class),
                $privateKey,
                $keyPassphrase,
            );

            // Enable grants
            $this->enableGrants($server);

            return $server;
        });

        $this->app->bind(ResourceServer::class, function () {
            $publicKey = config('oauth.public_key');

            return new ResourceServer(
                $this->app->make(AccessTokenRepositoryInterface::class),
                $publicKey,
            );
        });
    }

    /**
     * @throws BindingResolutionException
     * @throws DateMalformedIntervalStringException
     * @throws Exception
     */
    private function enableGrants(AuthorizationServer $server): void
    {
        $accessTokenTTL = new DateInterval(config('oauth.access_token_ttl', 'PT1H'));
        $refreshTokenTTL = new DateInterval(config('oauth.refresh_token_ttl', 'P1M'));
        $authCodeTTL = new DateInterval(config('oauth.auth_code_ttl', 'PT10M'));

        // Authorization Code Grant
        $authCodeGrant = new AuthCodeGrant(
            $this->app->make(AuthCodeRepositoryInterface::class),
            $this->app->make(RefreshTokenRepositoryInterface::class),
            $authCodeTTL,
        );
        $authCodeGrant->setRefreshTokenTTL($refreshTokenTTL);
        if (!config('oauth.require_code_challenge_for_public_clients', true)) {
            $authCodeGrant->disableRequireCodeChallengeForPublicClients();;
        }
        $server->enableGrantType($authCodeGrant, $accessTokenTTL);

        // Password Grant
        $passwordGrant = new PasswordGrant(
            $this->app->make(UserRepositoryInterface::class),
            $this->app->make(RefreshTokenRepositoryInterface::class),
        );
        $passwordGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($passwordGrant, $accessTokenTTL);

        // Pre-Authenticated Grant (for already authenticated users)
        $preAuthenticatedGrant = new PreAuthenticatedGrant(
            $this->app->make(UserRepositoryInterface::class),
            $this->app->make(RefreshTokenRepositoryInterface::class),
        );
        $preAuthenticatedGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($preAuthenticatedGrant, $accessTokenTTL);

        // Client Credentials Grant
        $server->enableGrantType(new ClientCredentialsGrant(), $accessTokenTTL);

        // Refresh Token Grant
        $refreshTokenGrant = new RefreshTokenGrant($this->app->make(RefreshTokenRepositoryInterface::class));
        $refreshTokenGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($refreshTokenGrant, $accessTokenTTL);

        // Device Code Grant
        $deviceCodeGrant = new DeviceCodeGrant(
            $this->app->make(DeviceCodeRepositoryInterface::class),
            $this->app->make(RefreshTokenRepositoryInterface::class),
            new DateInterval('PT' . config('oauth.device_code_ttl', 600) . 'S'),
            route('oauth.device.verify'),
            config('oauth.device_code_interval', 5),
        );
        $server->enableGrantType($deviceCodeGrant, $accessTokenTTL);
    }
}
