<?php

namespace App\Http\Integrations\LastFm;

use App\Models\User;
use App\Http\Integrations\LastFm\Handlers\{AuthHandler, LookupHandler, SearchHandler, TagHandler, UserHandler};
use App\Modules\Auth\LastFmCredentialService;
use GuzzleHttp\Client;

class LastFmClient
{
    public const string BASE_URL = 'https://ws.audioscrobbler.com/2.0/';

    public AuthHandler $auth;
    public SearchHandler $search;
    public LookupHandler $lookup;
    public TagHandler $tags;
    public UserHandler $user;

    public function __construct(
        private readonly Client $client,
        private readonly LastFmCredentialService $credentialService
    ) {
        // Create handlers directly in constructor
        $this->auth = new AuthHandler($this->client, self::BASE_URL);
        $this->search = new SearchHandler($this->client, self::BASE_URL);
        $this->lookup = new LookupHandler($this->client, self::BASE_URL);
        $this->tags = new TagHandler($this->client, self::BASE_URL);
        $this->user = new UserHandler($this->client, self::BASE_URL);

        // Automatically inject credential service into all handlers
        $this->injectCredentialService();
    }

    /**
     * Inject credential service into all handlers that extend BaseHandler
     */
    private function injectCredentialService(): void
    {
        $handlers = [$this->search, $this->lookup, $this->tags, $this->user];

        foreach ($handlers as $handler) {
            if (method_exists($handler, 'setCredentialService')) {
                $handler->setCredentialService($this->credentialService);
            }
        }
    }

    /**
     * Override session key for all handlers
     */
    public function setSessionKey(?string $sessionKey): void
    {
        $handlers = [$this->search, $this->lookup, $this->tags, $this->user];

        foreach ($handlers as $handler) {
            if (method_exists($handler, 'setSessionKey')) {
                $handler->setSessionKey($sessionKey);
            }
        }
    }

    /**
     * Get the current session key from credential service
     */
    public function getSessionKey(): ?string
    {
        if (!auth()->check()) {
            return null;
        }

        return $this->credentialService->getSessionKey(auth()->user());
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
    public function forUser(User $user): static
    {
        $sessionKey = $this->credentialService->getSessionKey($user);

        if ($sessionKey) {
            $this->setSessionKey($sessionKey);
        }

        return $this;
    }

    /**
     * Create a clone of this client with a specific session key
     */
    public function withSessionKey(?string $sessionKey): static
    {
        $clone = clone $this;
        $clone->setSessionKey($sessionKey);
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
    public function getCredentialService(): LastFmCredentialService
    {
        return $this->credentialService;
    }

    /**
     * Check if the client is configured for authenticated requests
     */
    public function isAuthenticated(): bool
    {
        return !empty($this->getSessionKey());
    }

    /**
     * Reset session key for all handlers (logout)
     */
    public function clearSession(): void
    {
        $this->setSessionKey(null);
    }

    /**
     * Clone the handlers when cloning the client
     */
    public function __clone()
    {
        // Clone handlers to prevent shared state
        $this->auth = clone $this->auth;
        $this->search = clone $this->search;
        $this->lookup = clone $this->lookup;
        $this->tags = clone $this->tags;
        $this->user = clone $this->user;

        // Re-inject credential service into cloned handlers
        $this->injectCredentialService();
    }
}