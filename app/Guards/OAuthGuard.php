<?php

declare(strict_types=1);

namespace App\Guards;

use App\Models\OAuth\Token;
use App\Models\User;
use App\Modules\OAuth\Psr7Factory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;

class OAuthGuard implements Guard
{
    /**
     * Cache for loaded authenticated user.
     */
    protected ?Authenticatable $user = null;

    /**
     * Cache for loaded OAuth token model.
     */
    protected ?Token $token = null;

    /**
     * Whether OAuth validation has been attempted.
     */
    protected bool $validated = false;

    public function __construct(
        private readonly Request $request,
        private readonly ResourceServer $resourceServer,
        private readonly Psr7Factory $psrFactory,
    ) {}

    public function check(): bool
    {
        return $this->authenticate()->user !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        return $this->authenticate()->user;
    }

    /**
     * Get the authenticated OAuth token.
     *
     * Returns null if no token is present or if token is not found in database.
     */
    public function token(): ?Token
    {
        $this->authenticate();

        return $this->token;
    }

    /**
     * Get the user ID without loading the full user model.
     */
    public function id(): ?string
    {
        $userId = $this->request->attributes->get('oauth_user_id');

        if ($userId === null && !$this->validated) {
            $this->authenticate();
            $userId = $this->request->attributes->get('oauth_user_id');
        }

        return $userId ? (string) $userId : null;
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

    /**
     * Forget the current user and token, allowing re-authentication.
     */
    public function forgetUser(): void
    {
        $this->user = null;
        $this->token = null;
        $this->validated = false;
    }

    /**
     * Perform OAuth authentication and load models.
     *
     * This method:
     * 1. Validates the OAuth access token via the resource server
     * 2. Extracts OAuth attributes (user_id, scopes, etc.)
     * 3. Loads the User model from database
     * 4. Loads the Token model from database (if available)
     * 5. Stores OAuth attributes in request for middleware use
     *
     * @return self Returns fluent interface for chaining
     */
    protected function authenticate(): self
    {
        // Return early if we've already attempted authentication
        if ($this->validated) {
            return $this;
        }

        $this->validated = true;

        try {
            // Validate OAuth token and extract attributes
            $attributes = $this->validateOAuthRequest();

            if ($attributes === null) {
                return $this;
            }

            // Store OAuth attributes in request for middleware use
            $this->storeOAuthAttributes($attributes);

            // Load models from database
            $this->loadModels($attributes);

        } catch (OAuthServerException $exception) {
            // Invalid token - leave user and token as null
        }

        return $this;
    }

    /**
     * Validate the OAuth request and extract attributes.
     *
     * @return array|null OAuth attributes or null if validation fails
     */
    protected function validateOAuthRequest(): ?array
    {
        $psrRequest = $this->psrFactory->createRequest($this->request);
        $validatedRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);

        return [
            'user_id'   => $validatedRequest->getAttribute('oauth_user_id'),
            'token_id'  => $validatedRequest->getAttribute('oauth_access_token_id'),
            'client_id' => $validatedRequest->getAttribute('oauth_client_id'),
            'scopes'    => $validatedRequest->getAttribute('oauth_scopes', []),
        ];
    }

    /**
     * Store OAuth attributes in the request for later use by middleware.
     */
    protected function storeOAuthAttributes(array $attributes): void
    {
        $this->request->attributes->set('oauth_user_id', $attributes['user_id']);
        $this->request->attributes->set('oauth_client_id', $attributes['client_id']);
        $this->request->attributes->set('oauth_scopes', $attributes['scopes']);
    }

    /**
     * Load User and Token models from database using OAuth attributes.
     */
    protected function loadModels(array $attributes): void
    {
        $userId = $attributes['user_id'];
        $tokenId = $attributes['token_id'];

        // Load user model
        if ($userId !== null) {
            $this->user = User::find($userId);
        }

        // Load token model (may be null if token was deleted)
        if ($tokenId !== null) {
            $this->token = Token::where('token_id', $tokenId)->first();
        }
    }
}
