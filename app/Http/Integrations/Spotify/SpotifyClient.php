<?php

namespace App\Http\Integrations\Spotify;

use App\Http\Integrations\Spotify\Handlers\{AuthHandler, SearchHandler, UserHandler, GenreHandler};
use App\Modules\Auth\SpotifyCredentialService;
use GuzzleHttp\Client;

class SpotifyClient
{
    public const string BASE_URL = 'https://api.spotify.com/v1/';

    public AuthHandler $auth;
    public SearchHandler $search;
    public UserHandler $user;
    public GenreHandler $genres;

    public function __construct(
        private readonly Client $client,
        private readonly SpotifyCredentialService $credentialService
    ) {
        // Create handlers directly in constructor
        $this->auth = new AuthHandler($this->client, self::BASE_URL);
        $this->search = new SearchHandler($this->client, self::BASE_URL);
        $this->user = new UserHandler($this->client, self::BASE_URL);
        $this->genres = new GenreHandler($this->client, self::BASE_URL);

        // Automatically inject credential service into all handlers
        $this->injectCredentialService();
    }

    /**
     * Inject credential service into all handlers that extend BaseHandler
     */
    private function injectCredentialService(): void
    {
        $handlers = [$this->search, $this->user, $this->genres];

        foreach ($handlers as $handler) {
            if (method_exists($handler, 'setCredentialService')) {
                $handler->setCredentialService($this->credentialService);
            }
        }
    }

    /**
     * Override access token for all handlers
     */
    public function setAccessToken(?string $accessToken): void
    {
        $handlers = [$this->search, $this->user, $this->genres];

        foreach ($handlers as $handler) {
            if (method_exists($handler, 'setAccessToken')) {
                $handler->setAccessToken($accessToken);
            }
        }
    }

    /**
     * Get the current access token from credential service
     */
    public function getAccessToken(): ?string
    {
        if (!auth()->check()) {
            return null;
        }

        return $this->credentialService->getAccessToken(auth()->user());
    }

    /**
     * Check if current user has valid credentials
     */
    public function hasValidCredentials(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        return $this->credentialService->hasValidCredentials(auth()->user());
    }

    /**
     * Get authenticated client for a specific user
     */
    public function forUser(\App\Models\User $user): static
    {
        $accessToken = $this->credentialService->getAccessToken($user);

        if ($accessToken) {
            $this->setAccessToken($accessToken);
        }

        return $this;
    }

    /**
     * Create a clone of this client with a specific access token
     */
    public function withAccessToken(?string $accessToken): static
    {
        $clone = clone $this;
        $clone->setAccessToken($accessToken);
        return $clone;
    }

    /**
     * Get the base URL
     */
    public function getBaseUrl(): string
    {
        return self::BASE_URL;
    }

    /**
     * Get the HTTP client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the credential service
     */
    public function getCredentialService(): SpotifyCredentialService
    {
        return $this->credentialService;
    }

    /**
     * Check if the client is configured for authenticated requests
     */
    public function isAuthenticated(): bool
    {
        return !empty($this->getAccessToken());
    }

    /**
     * Reset access token for all handlers (logout)
     */
    public function clearSession(): void
    {
        $this->setAccessToken(null);
    }

    /**
     * Clone the handlers when cloning the client
     */
    public function __clone()
    {
        // Clone handlers to prevent shared state
        $this->auth = clone $this->auth;
        $this->search = clone $this->search;
        $this->user = clone $this->user;
        $this->genres = clone $this->genres;

        // Re-inject credential service into cloned handlers
        $this->injectCredentialService();
    }
}