<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Guards;

use App\Models\Auth\OAuth\Token;
use App\Models\Auth\Passkey;
use App\Models\User;
use App\Modules\Auth\OAuth\Psr7Factory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * Cache for loaded passkey model (when authenticated via passkey).
     */
    protected ?Passkey $passkey = null;

    /**
     * Whether validation has been attempted.
     */
    protected bool $validated = false;

    /**
     * The authentication method used ('oauth' or 'passkey').
     */
    protected ?string $authMethod = null;

    public function __construct(
        private readonly Request        $request,
        private readonly ResourceServer $resourceServer,
        private readonly Psr7Factory    $psrFactory,
    )
    {
    }

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
     * Returns null if authenticated via passkey or if token is not found in database.
     */
    public function token(): ?Token
    {
        $this->authenticate();

        return $this->token;
    }

    /**
     * Get the authenticated passkey.
     *
     * Returns null if authenticated via OAuth token or if passkey is not found.
     */
    public function passkey(): ?Passkey
    {
        $this->authenticate();

        return $this->passkey;
    }

    /**
     * Get the authentication method used ('oauth' or 'passkey').
     */
    public function authMethod(): ?string
    {
        $this->authenticate();

        return $this->authMethod;
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

        return $userId ? (string)$userId : null;
    }

    public function validate(array $credentials = []): bool
    {
        return false; // OAuth/passkey validation is handled by the guard
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
     * Forget the current user, token, and passkey, allowing re-authentication.
     */
    public function forgetUser(): void
    {
        $this->user = null;
        $this->token = null;
        $this->passkey = null;
        $this->validated = false;
        $this->authMethod = null;
    }

    /**
     * Perform authentication using OAuth token or passkey assertion.
     *
     * This method tries to authenticate using:
     * 1. OAuth bearer token (Authorization: Bearer <token>)
     * 2. Passkey assertion (X-Passkey-Assertion header with X-Passkey-Challenge)
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

        // Try OAuth token authentication first
        if ($this->hasOAuthToken()) {
            return $this->authenticateViaOAuth();
        }

        // Try passkey authentication
        if ($this->hasPasskeyAssertion()) {
            return $this->authenticateViaPasskey();
        }

        return $this;
    }

    /**
     * Check if the request has an OAuth bearer token.
     */
    protected function hasOAuthToken(): bool
    {
        return $this->request->bearerToken() !== null;
    }

    /**
     * Check if the request has passkey assertion headers.
     */
    protected function hasPasskeyAssertion(): bool
    {
        return $this->request->hasHeader('X-Passkey-Assertion')
            && $this->request->hasHeader('X-Passkey-Challenge');
    }

    /**
     * Authenticate using OAuth bearer token.
     */
    protected function authenticateViaOAuth(): self
    {
        try {
            $attributes = $this->validateOAuthRequest();

            if ($attributes === null) {
                return $this;
            }

            $this->storeOAuthAttributes($attributes);
            $this->loadModelsFromOAuth($attributes);
            $this->authMethod = 'oauth';

        } catch (\Exception $e) {
            Log::alert('[OAuthGuard]: failed oauth authentication attempt', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $this;
    }

    /**
     * Authenticate using passkey assertion.
     */
    protected function authenticateViaPasskey(): self
    {
        try {
            $assertionJson = $this->request->header('X-Passkey-Assertion');
            $challengeId = $this->request->header('X-Passkey-Challenge');

            // Retrieve the challenge options from cache
            $challengeOptions = Cache::get("passkey_challenge:{$challengeId}");

            if (!$challengeOptions) {
                return $this;
            }

            $passkey = $this->validatePasskeyAssertion($assertionJson, $challengeOptions);

            if (!$passkey) {
                return $this;
            }

            // Load the user from the passkey
            $this->user = $passkey->user;
            $this->passkey = $passkey;
            $this->authMethod = 'passkey';

            // Store passkey info in request for middleware
            $this->request->attributes->set('passkey_id', $passkey->id);
            $this->request->attributes->set('oauth_scopes', ['access-api',
                                                             'access-broadcasting']); // Default scopes for passkey auth

            // Delete the challenge after successful authentication
            Cache::forget("passkey_challenge:{$challengeId}");

        } catch (\Exception $e) {
            Log::alert('[OAuthGuard]: failed passkey authentication attempt', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $this;
    }

    /**
     * Validate the OAuth request and extract attributes.
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
     * Validate passkey assertion and return the passkey.
     */
    protected function validatePasskeyAssertion(string $assertionJson, string $challengeOptionsJson): ?Passkey
    {
        $publicKeyCredential = $this->deserializePublicKeyCredential($assertionJson);

        if (!$publicKeyCredential) {
            return null;
        }

        $passkey = Passkey::where('credential_id', Passkey::encodeBase64($publicKeyCredential->rawId))->first();

        if (!$passkey) {
            return null;
        }

        $challengeOptions = $this->deserializeChallengeOptions($challengeOptionsJson);

        if (!$this->verifyPasskeyAssertion($publicKeyCredential, $challengeOptions, $passkey)) {
            return null;
        }

        return $passkey;
    }

    /**
     * Deserialize the public key credential from JSON.
     */
    protected function deserializePublicKeyCredential(string $json): ?\Webauthn\PublicKeyCredential
    {
        try {
            $webauthnService = app(\App\Modules\Auth\Webauthn\WebauthnService::class);
            $credential = $webauthnService->deserialize($json, \Webauthn\PublicKeyCredential::class);

            if (!$credential->response instanceof \Webauthn\AuthenticatorAssertionResponse) {
                return null;
            }

            return $credential;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Deserialize the challenge options from JSON.
     */
    protected function deserializeChallengeOptions(string $json): ?\Webauthn\PublicKeyCredentialRequestOptions
    {
        try {
            $webauthnService = app(\App\Modules\Auth\Webauthn\WebauthnService::class);
            return $webauthnService->deserialize($json, \Webauthn\PublicKeyCredentialRequestOptions::class);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verify the passkey assertion signature.
     */
    protected function verifyPasskeyAssertion(
        \Webauthn\PublicKeyCredential               $credential,
        \Webauthn\PublicKeyCredentialRequestOptions $options,
        Passkey                                     $passkey,
    ): bool
    {
        try {
            $csmFactory = new \Webauthn\CeremonyStep\CeremonyStepManagerFactory();
            $requestCsm = $csmFactory->requestCeremony();

            $validator = \Webauthn\AuthenticatorAssertionResponseValidator::create($requestCsm);

            $publicKeyCredentialSource = $validator->check(
                publicKeyCredentialSource: $passkey->data,
                authenticatorAssertionResponse: $validator->response,
                publicKeyCredentialRequestOptions: $options,
                host: parse_url(config('app.url'), PHP_URL_HOST),
                userHandle: null,
            );

            // Update passkey with new counter and last used time
            $passkey->update([
                'data'         => $publicKeyCredentialSource,
                'last_used_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::alert('[OAuthGuard]: failed passkey assertion validation attempt', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Load User and Token models from database using OAuth attributes.
     */
    protected function loadModelsFromOAuth(array $attributes): void
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
