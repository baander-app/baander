<?php

namespace App\Http\Integrations\LastFm\Handlers;

use App\Modules\Auth\LastFmCredentialService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;

abstract class BaseHandler
{
    protected ?LastFmCredentialService $credentialService = null;
    protected ?string $sessionKey = null;

    public function __construct(
        protected readonly Client $client,
        protected readonly string $baseUrl
    ) {}

    /**
     * Set the credential service for automatic session management
     */
    public function setCredentialService(LastFmCredentialService $credentialService): void
    {
        $this->credentialService = $credentialService;
    }

    /**
     * Manually set session key (overrides automatic detection)
     */
    public function setSessionKey(?string $sessionKey): void
    {
        $this->sessionKey = $sessionKey;
    }

    /**
     * Get session key for authenticated requests
     */
    protected function getSessionKey(): ?string
    {
        // Use manually set session key if available
        if ($this->sessionKey) {
            return $this->sessionKey;
        }

        // Try to get session key from credential service if available and user is authenticated
        if ($this->credentialService && auth()->check()) {
            return $this->credentialService->getSessionKey(auth()->user());
        }

        return null;
    }

    /**
     * Build query parameters with automatic session key injection
     */
    protected function buildQueryParams(array $params): array
    {
        $queryParams = array_merge([
            'api_key' => config('services.lastfm.key'),
            'format' => 'json',
        ], $params);

        // Add session key if available for authenticated requests
        if ($sessionKey = $this->getSessionKey()) {
            $queryParams['sk'] = $sessionKey;
        }

        return $queryParams;
    }

    /**
     * Make synchronous API request with error handling
     */
    protected function makeRequest(array $params): array
    {
        try {
            $response = $this->client->get($this->baseUrl, [
                'query' => $this->buildQueryParams($params),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Check for Last.fm API errors
            if (isset($data['error'])) {
                Log::warning('Last.fm API error', [
                    'error' => $data['error'],
                    'message' => $data['message'] ?? 'Unknown error',
                    'params' => $params
                ]);
                return [];
            }

            return $data;
        } catch (RequestException $e) {
            Log::error('Last.fm API request failed', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            return [];
        }
    }

    /**
     * Make asynchronous API request returning a promise
     */
    protected function makeRequestAsync(array $params): PromiseInterface
    {
        return $this->client->getAsync($this->baseUrl, [
            'query' => $this->buildQueryParams($params),
        ])->then(
            function ($response) use ($params) {
                $data = json_decode($response->getBody()->getContents(), true);

                // Check for Last.fm API errors
                if (isset($data['error'])) {
                    Log::warning('Last.fm API error (async)', [
                        'error' => $data['error'],
                        'message' => $data['message'] ?? 'Unknown error',
                        'params' => $params
                    ]);
                    return [];
                }

                return $data;
            },
            function (RequestException $e) use ($params) {
                Log::error('Last.fm API request failed (async)', [
                    'error' => $e->getMessage(),
                    'params' => $params
                ]);
                return [];
            }
        );
    }

    /**
     * Check if current user has valid credentials
     */
    protected function hasValidCredentials(): bool
    {
        if (!$this->credentialService || !auth()->check()) {
            return false;
        }

        return $this->credentialService->hasValidCredentials(auth()->user());
    }

    /**
     * Require authenticated session for the request
     */
    protected function requireAuthentication(): bool
    {
        if (!$this->getSessionKey()) {
            Log::warning('Last.fm API request requires authentication but no session key available');
            return false;
        }
        return true;
    }
}