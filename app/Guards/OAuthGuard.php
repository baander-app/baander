<?php

declare(strict_types=1);

namespace App\Guards;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class OAuthGuard implements Guard
{
    protected ?Authenticatable $user = null;
    protected Request $request;
    protected ResourceServer $resourceServer;
    protected PsrHttpFactory $psrFactory;

    public function __construct(
        Request $request,
        ResourceServer $resourceServer,
        PsrHttpFactory $psrFactory
    ) {
        $this->request = $request;
        $this->resourceServer = $resourceServer;
        $this->psrFactory = $psrFactory;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        try {
            $psrRequest = $this->psrFactory->createRequest($this->request);
            $psrRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);

            $userId = $psrRequest->getAttribute('oauth_user_id');

            if ($userId) {
                $this->user = User::find($userId);

                // Store OAuth attributes for later use
                $this->request->attributes->set('oauth_user_id', $userId);
                $this->request->attributes->set('oauth_client_id', $psrRequest->getAttribute('oauth_client_id'));
                $this->request->attributes->set('oauth_scopes', $psrRequest->getAttribute('oauth_scopes', []));
            }

        } catch (OAuthServerException $exception) {
            $this->user = null;
        }

        return $this->user;
    }

    public function id(): ?string
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false; // OAuth validation is handled by the resource server
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
    }
}
