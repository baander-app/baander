<?php

namespace App\Events\OAuth;

use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\Token;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

class AccessTokenCreatedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Token $accessToken,
        public User $user,
        public Client $client,
        public ?string $grantType = null,
        public array $scopes = [],
        public ?AuthorizationRequest $authRequest = null,
    ) {
        //
    }

    /**
     * Check if this is a refresh token grant.
     */
    public function isRefreshGrant(): bool
    {
        return $this->grantType === 'refresh_token';
    }

    /**
     * Check if this is an authorization code grant.
     */
    public function isAuthorizationCodeGrant(): bool
    {
        return $this->grantType === 'authorization_code';
    }

    /**
     * Check if this is a client credentials grant.
     */
    public function isClientCredentialsGrant(): bool
    {
        return $this->grantType === 'client_credentials';
    }

    /**
     * Check if this is a device code grant.
     */
    public function isDeviceCodeGrant(): bool
    {
        return $this->grantType === 'urn:ietf:params:oauth:grant-type:device_code';
    }
}
